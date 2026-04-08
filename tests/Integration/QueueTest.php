<?php

function test_queue(): void {
    TestCase::suite('Async Queue System');
    test_reset_globals();

    // Install queue table
    $installed = CR_Queue::install();
    TestCase::assertTrue($installed, 'Queue table created');

    // Push a job
    $job_id = CR_Queue::push('test_job_hook', ['arg1', 'arg2']);
    TestCase::assertNotEqual(false, $job_id, 'push returns job ID');
    TestCase::assertGreaterThan(0, $job_id, 'Job ID is positive');

    // Push with options
    $job_id2 = CR_Queue::push('priority_job', [], ['priority' => 1, 'group' => 'emails']);
    TestCase::assertNotEqual(false, $job_id2, 'push with options returns ID');

    // Push with delay
    $job_id3 = CR_Queue::push('delayed_job', [], ['delay' => 3600]);
    TestCase::assertNotEqual(false, $job_id3, 'push with delay returns ID');

    // Stats show pending jobs
    $stats = CR_Queue::stats();
    TestCase::assertGreaterThan(0, $stats['pending'], 'Stats show pending jobs');

    // Process batch - should run immediate jobs
    $executed = [];
    add_action('test_job_hook', function ($a1, $a2) use (&$executed) {
        $executed = [$a1, $a2];
    }, 10, 2);
    add_action('priority_job', function () use (&$executed) {
        $executed[] = 'priority_ran';
    });

    $processed = CR_Queue::process_batch(10);
    TestCase::assertGreaterThan(0, $processed, 'process_batch processes jobs');
    TestCase::assertEqual(['arg1', 'arg2'], $executed, 'Job hook fires with correct args');

    // Delayed job should NOT have been processed
    $stats = CR_Queue::stats();
    TestCase::assertGreaterThan(0, $stats['pending'], 'Delayed job still pending');

    // Completed stats
    TestCase::assertGreaterThan(0, $stats['completed'], 'Completed jobs counted');

    // Schedule recurring job
    $rec_id = CR_Queue::schedule('recurring_test', 3600, ['param' => 'val'], 'hourly');
    TestCase::assertNotEqual(false, $rec_id, 'schedule returns ID');

    // Duplicate schedule returns existing ID
    $rec_id2 = CR_Queue::schedule('recurring_test', 3600);
    TestCase::assertEqual($rec_id, $rec_id2, 'Duplicate schedule returns existing ID');

    // Unschedule
    $result = CR_Queue::unschedule('recurring_test');
    TestCase::assertTrue($result, 'unschedule returns true');

    // Job failure and retry
    $fail_id = CR_Queue::push('failing_job', [], ['max_attempts' => 2]);
    add_action('failing_job', function () {
        throw new RuntimeException('Intentional test failure');
    });

    CR_Queue::process_batch(10);

    // After first failure, should be rescheduled (pending again)
    $db = cr_db();
    $job = $db->get_row($db->prepare(
        "SELECT * FROM `{$db->prefix}queue` WHERE id = %d", $fail_id
    ));
    TestCase::assertEqual('pending', $job->status, 'Failed job retried (pending again)');
    TestCase::assertEqual(1, (int) $job->attempts, 'Attempts incremented');
    TestCase::assertNotEmpty($job->last_error, 'Error message recorded');

    // Second failure should move to dead letter
    CR_Queue::process_batch(10);
    // Wait - the job was rescheduled with backoff, so it won't be processed yet.
    // Force it by updating scheduled_at
    $db->update($db->prefix . 'queue', ['scheduled_at' => gmdate('Y-m-d H:i:s')], ['id' => $fail_id]);
    CR_Queue::process_batch(10);

    $job = $db->get_row($db->prepare(
        "SELECT * FROM `{$db->prefix}queue` WHERE id = %d", $fail_id
    ));
    TestCase::assertEqual('dead', $job->status, 'Job moved to dead letter after max attempts');

    // Dead letter queue
    $dead = CR_Queue::get_dead_letter();
    TestCase::assertGreaterThan(0, count($dead), 'get_dead_letter returns failed jobs');

    // Retry dead job
    $retried = CR_Queue::retry_job($fail_id);
    TestCase::assertTrue($retried, 'retry_job returns true');
    $job = $db->get_row($db->prepare(
        "SELECT * FROM `{$db->prefix}queue` WHERE id = %d", $fail_id
    ));
    TestCase::assertEqual('pending', $job->status, 'Retried job is pending again');
    TestCase::assertEqual(0, (int) $job->attempts, 'Retried job has 0 attempts');

    // Cleanup - need some completed/dead jobs to clean
    // Re-mark the retried job as dead so cleanup has something to work with
    $db->update($db->prefix . 'queue', [
        'status' => 'dead',
        'completed_at' => gmdate('Y-m-d H:i:s', time() - 86400),
    ], ['id' => $fail_id]);
    $cleaned = CR_Queue::cleanup(0); // Clean everything
    TestCase::assertGreaterThan(0, $cleaned, 'cleanup removes old completed/dead jobs');

    // Convenience functions
    $id = cr_queue_push('convenience_hook', ['data']);
    TestCase::assertGreaterThan(0, $id, 'cr_queue_push convenience works');

    $id = cr_schedule_event('daily_hook', 'daily');
    TestCase::assertGreaterThan(0, $id, 'cr_schedule_event convenience works');

    cr_unschedule_event('daily_hook');

    // Final cleanup
    $db->query("DROP TABLE IF EXISTS `{$db->prefix}queue`");
}
