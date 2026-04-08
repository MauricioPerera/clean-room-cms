<?php
/**
 * Clean Room CMS - Queue Worker
 *
 * Processes async jobs from the queue. Run via system cron or supervisor:
 *
 *   # Process one batch and exit (for crontab):
 *   * * * * * php /path/to/worker.php
 *
 *   # Run continuously (for supervisor/systemd):
 *   php worker.php --daemon
 *
 *   # Process specific number of jobs:
 *   php worker.php --batch=10
 */

require_once __DIR__ . '/wp-config.php';

// Initialize without template loading
cr_db()->connect();

if (!cr_is_installed()) {
    echo "CMS not installed.\n";
    exit(1);
}

cr_load_autoloaded_options();
cr_register_default_roles();
cr_load_plugins();
cr_register_default_post_types();
cr_register_default_taxonomies();

do_action('plugins_loaded');
do_action('init');

// Parse CLI args
$daemon = in_array('--daemon', $argv ?? []);
$batch_size = 5;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch_size = (int) substr($arg, 8);
    }
}

if ($daemon) {
    echo "[Worker] Running in daemon mode (Ctrl+C to stop)...\n";
    while (true) {
        $processed = CR_Queue::process_batch($batch_size);
        if ($processed > 0) {
            echo "[Worker] Processed {$processed} jobs.\n";
        }
        sleep(5);
    }
} else {
    $processed = CR_Queue::process_batch($batch_size);
    if ($processed > 0) {
        echo "[Worker] Processed {$processed} jobs.\n";
    } else {
        echo "[Worker] No pending jobs.\n";
    }
}
