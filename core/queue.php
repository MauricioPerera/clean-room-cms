<?php
/**
 * Clean Room CMS - Async Queue System
 *
 * Replaces WordPress's pseudo-cron (wp-cron) which runs on page load and
 * blocks the response. This system provides:
 *
 *   1. Database-backed job queue with priorities
 *   2. Scheduled jobs (run at specific times)
 *   3. Recurring jobs (repeat at intervals)
 *   4. Job groups for batch operations
 *   5. Retry with exponential backoff on failure
 *   6. Dead letter queue for permanently failed jobs
 *   7. Worker that processes jobs without blocking page loads
 *
 * Jobs are processed by running: php worker.php (via cron or supervisor)
 * Or non-blocking: cr_queue_process_batch() at end of request via shutdown hook.
 */

class CR_Queue {
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_DEAD      = 'dead';

    private static int $max_retries = 3;
    private static int $batch_size = 5;

    /**
     * SQL to create the queue table.
     */
    public static function schema(): string {
        $prefix = cr_db()->prefix;
        return "CREATE TABLE IF NOT EXISTS `{$prefix}queue` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `hook` VARCHAR(200) NOT NULL,
            `args` LONGTEXT NOT NULL DEFAULT '[]',
            `group_name` VARCHAR(100) NOT NULL DEFAULT '',
            `priority` INT NOT NULL DEFAULT 10,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` DATETIME DEFAULT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `max_attempts` INT NOT NULL DEFAULT 3,
            `last_error` TEXT DEFAULT NULL,
            `recurrence` VARCHAR(50) DEFAULT NULL,
            `interval_seconds` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `status_scheduled` (`status`, `scheduled_at`),
            KEY `hook` (`hook`),
            KEY `group_name` (`group_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Install the queue table.
     */
    public static function install(): bool {
        $result = cr_db()->query(self::schema());
        return $result !== false;
    }

    /**
     * Add a job to the queue.
     *
     * @param string $hook Action hook name to fire when job runs
     * @param array $args Arguments to pass to the hook
     * @param array $options Optional: priority, group, delay, max_attempts
     * @return int|false Job ID or false
     */
    public static function push(string $hook, array $args = [], array $options = []): int|false {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        $priority = (int) ($options['priority'] ?? 10);
        $group = $options['group'] ?? '';
        $delay = (int) ($options['delay'] ?? 0);
        $max_attempts = (int) ($options['max_attempts'] ?? self::$max_retries);

        // Prevent oversized job arguments (10KB limit)
        $encoded_args = json_encode($args);
        if (strlen($encoded_args) > 10240) {
            return false;
        }

        $scheduled_at = $delay > 0
            ? gmdate('Y-m-d H:i:s', time() + $delay)
            : gmdate('Y-m-d H:i:s');

        return $db->insert($table, [
            'hook'             => $hook,
            'args'             => json_encode($args),
            'group_name'       => $group,
            'priority'         => $priority,
            'status'           => self::STATUS_PENDING,
            'scheduled_at'     => $scheduled_at,
            'attempts'         => 0,
            'max_attempts'     => $max_attempts,
        ]);
    }

    /**
     * Schedule a recurring job.
     *
     * @param string $hook Action hook name
     * @param int $interval_seconds Seconds between executions
     * @param array $args Arguments
     * @param string $recurrence Label (e.g., 'hourly', 'daily', 'twicedaily')
     */
    public static function schedule(string $hook, int $interval_seconds, array $args = [], string $recurrence = ''): int|false {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        // Check if already scheduled
        $exists = $db->get_var($db->prepare(
            "SELECT id FROM `{$table}` WHERE hook = %s AND recurrence IS NOT NULL AND status != 'dead' LIMIT 1",
            $hook
        ));

        if ($exists) return (int) $exists;

        return $db->insert($table, [
            'hook'             => $hook,
            'args'             => json_encode($args),
            'priority'         => 10,
            'status'           => self::STATUS_PENDING,
            'scheduled_at'     => gmdate('Y-m-d H:i:s'),
            'attempts'         => 0,
            'max_attempts'     => 0, // infinite for recurring
            'recurrence'       => $recurrence ?: 'custom',
            'interval_seconds' => $interval_seconds,
        ]);
    }

    /**
     * Unschedule a recurring job.
     */
    public static function unschedule(string $hook): bool {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        $result = $db->query($db->prepare(
            "DELETE FROM `{$table}` WHERE hook = %s AND recurrence IS NOT NULL",
            $hook
        ));

        return $result !== false;
    }

    /**
     * Process a batch of pending jobs.
     * Returns the number of jobs processed.
     */
    public static function process_batch(int $batch_size = 0): int {
        if ($batch_size <= 0) $batch_size = self::$batch_size;

        $db = cr_db();
        $table = $db->prefix . 'queue';
        $now = gmdate('Y-m-d H:i:s');

        // Claim jobs atomically (prevent double processing)
        $claim_id = bin2hex(random_bytes(8));
        $db->query($db->prepare(
            "UPDATE `{$table}` SET status = 'running', started_at = %s, last_error = %s
             WHERE status = 'pending' AND scheduled_at <= %s
             ORDER BY priority ASC, scheduled_at ASC LIMIT %d",
            $now, $claim_id, $now, $batch_size
        ));

        $jobs = $db->get_results($db->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'running' AND last_error = %s ORDER BY priority ASC LIMIT %d",
            $claim_id, $batch_size
        ));

        $processed = 0;
        foreach ($jobs as $job) {
            $processed++;
            self::execute_job($job);
        }

        return $processed;
    }

    /**
     * Execute a single job.
     */
    private static function execute_job(object $job): void {
        $db = cr_db();
        $table = $db->prefix . 'queue';
        $args = json_decode($job->args, true) ?: [];

        try {
            // Fire the action hook with the job's arguments
            do_action($job->hook, ...$args);

            if ($job->recurrence !== null) {
                // Reschedule recurring job
                $next = gmdate('Y-m-d H:i:s', time() + (int) $job->interval_seconds);
                $db->update($table, [
                    'status'       => self::STATUS_PENDING,
                    'scheduled_at' => $next,
                    'started_at'   => null,
                    'completed_at' => null,
                    'attempts'     => (int) $job->attempts + 1,
                    'last_error'   => null,
                ], ['id' => $job->id]);
            } else {
                // Mark completed
                $db->update($table, [
                    'status'       => self::STATUS_COMPLETED,
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                    'attempts'     => (int) $job->attempts + 1,
                ], ['id' => $job->id]);
            }

            do_action('cr_queue_job_completed', $job);

        } catch (\Throwable $e) {
            $attempts = (int) $job->attempts + 1;
            $max = (int) $job->max_attempts;

            if ($max > 0 && $attempts >= $max) {
                // Move to dead letter queue
                $db->update($table, [
                    'status'     => self::STATUS_DEAD,
                    'attempts'   => $attempts,
                    'last_error' => $e->getMessage(),
                ], ['id' => $job->id]);

                do_action('cr_queue_job_dead', $job, $e);
            } else {
                // Retry with exponential backoff
                $backoff = min(3600, (int) pow(2, $attempts) * 30); // 30s, 60s, 120s, ... max 1hr
                $retry_at = gmdate('Y-m-d H:i:s', time() + $backoff);

                $db->update($table, [
                    'status'       => self::STATUS_PENDING,
                    'scheduled_at' => $retry_at,
                    'started_at'   => null,
                    'attempts'     => $attempts,
                    'last_error'   => $e->getMessage(),
                ], ['id' => $job->id]);

                do_action('cr_queue_job_retry', $job, $attempts, $backoff);
            }
        }
    }

    /**
     * Get queue statistics.
     */
    public static function stats(): array {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        $rows = $db->get_results("SELECT status, COUNT(*) as count FROM `{$table}` GROUP BY status");

        $stats = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($rows as $row) {
            $stats[$row->status] = (int) $row->count;
        }

        $stats['total'] = array_sum($stats);
        $stats['next_job'] = $db->get_var(
            "SELECT scheduled_at FROM `{$table}` WHERE status = 'pending' ORDER BY priority ASC, scheduled_at ASC LIMIT 1"
        );

        return $stats;
    }

    /**
     * Clean up completed jobs older than $days.
     */
    public static function cleanup(int $days = 7): int {
        $db = cr_db();
        $table = $db->prefix . 'queue';
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $db->query("DELETE FROM `{$table}` WHERE status IN ('completed', 'dead') AND completed_at < '{$cutoff}'");
        return $db->rows_affected;
    }

    /**
     * Get failed/dead jobs for inspection.
     */
    public static function get_dead_letter(int $limit = 50): array {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        return $db->get_results(
            "SELECT * FROM `{$table}` WHERE status = 'dead' ORDER BY created_at DESC LIMIT " . intval($limit)
        );
    }

    /**
     * Retry a dead job.
     */
    public static function retry_job(int $job_id): bool {
        $db = cr_db();
        $table = $db->prefix . 'queue';

        $result = $db->update($table, [
            'status'       => self::STATUS_PENDING,
            'scheduled_at' => gmdate('Y-m-d H:i:s'),
            'started_at'   => null,
            'completed_at' => null,
            'attempts'     => 0,
            'last_error'   => null,
        ], ['id' => $job_id]);

        return $result !== false;
    }
}

// -- Convenience functions --

/**
 * Add a one-time async job.
 */
function cr_queue_push(string $hook, array $args = [], array $options = []): int|false {
    return CR_Queue::push($hook, $args, $options);
}

/**
 * Schedule a recurring job.
 */
function cr_schedule_event(string $hook, string $recurrence, array $args = []): int|false {
    $intervals = [
        'minutely'   => 60,
        'hourly'     => 3600,
        'twicedaily' => 43200,
        'daily'      => 86400,
        'weekly'     => 604800,
    ];

    $seconds = $intervals[$recurrence] ?? 3600;
    return CR_Queue::schedule($hook, $seconds, $args, $recurrence);
}

/**
 * Unschedule a recurring job.
 */
function cr_unschedule_event(string $hook): bool {
    return CR_Queue::unschedule($hook);
}

/**
 * Process queue jobs at end of request (non-blocking alternative).
 * Limited to prevent slowing down the response.
 */
function cr_queue_process_on_shutdown(): void {
    CR_Queue::process_batch(2); // Process max 2 jobs per request
}

// Optionally process queue on shutdown (like wp-cron but limited)
add_action('shutdown', 'cr_queue_process_on_shutdown');
