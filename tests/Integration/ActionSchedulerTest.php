<?php

declare(strict_types=1);

use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Tests\Integration\Support\Cron;
use Pollora\Schedule\Tests\Integration\Support\RecordingJob;

/**
 * The Action Scheduler driver against the real plugin (the job installs it with `wordpress-plugins: action-scheduler`):
 * its store, its queue runner and the `cron` option that indexes what it keeps.
 */
const AS_HOOKS = ['pollora_int_as', 'pollora_int_plugin', 'pollora_int_as_due'];

beforeEach(function (): void {
    expect(function_exists('as_schedule_single_action'))->toBeTrue('Action Scheduler is not installed: the job needs wordpress-plugins: action-scheduler.');

    Cron::freshEngine();
    delete_option(RecordingJob::OPTION);
});

afterEach(function (): void {
    foreach (AS_HOOKS as $hook) {
        foreach (as_get_scheduled_actions(['hook' => $hook, 'per_page' => -1, 'status' => ''], 'ids') as $id) {
            ActionScheduler::store()->delete_action((string) $id);
        }

        wp_unschedule_hook($hook);
        remove_all_actions($hook);
    }

    // The driver hangs its filters on WordPress for the rest of the process: a test that follows starts without them
    foreach (['pre_schedule_event', 'pre_clear_scheduled_hook', 'pre_unschedule_hook', 'pre_get_ready_cron_jobs', 'pre_update_option_cron', 'pre_reschedule_event', 'pre_unschedule_event'] as $filter) {
        remove_all_filters($filter);
    }

    foreach (['action_scheduler_deleted_action', 'action_scheduler_stored_action', 'action_scheduler_completed_action', 'action_scheduler_failed_action', 'action_scheduler_canceled_action'] as $action) {
        remove_all_actions($action);
    }

    delete_option(RecordingJob::OPTION);
});

it('keeps an event in the store of Action Scheduler and lists it in the cron option, where WordPress finds it', function (): void {
    Schedule::driver('action-scheduler');
    $at = time() + 7200;

    Schedule::once('pollora_int_as')->at($at)->with(['order' => 7])->call(RecordingJob::class);

    $entries = Cron::entries('pollora_int_as');

    expect(as_next_scheduled_action('pollora_int_as', ['order' => 7], ActionSchedulerDriver::GROUP))->toBe($at)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0][CronIndex::MARKER])->toBe('action-scheduler')
        ->and(wp_next_scheduled('pollora_int_as', ['order' => 7]))->toBe($at)
        ->and(Schedule::next('pollora_int_as', ['order' => 7])?->getTimestamp())->toBe($at)
        ->and(Schedule::cancel('pollora_int_as', ['order' => 7]))->toBe(1)
        ->and(as_has_scheduled_action('pollora_int_as', ['order' => 7], ActionSchedulerDriver::GROUP))->toBeFalse()
        ->and(Cron::entries('pollora_int_as'))->toBe([]);
});

it('sends what a plugin schedules with the core functions to Action Scheduler, and cancels it from there', function (): void {
    Schedule::driver('action-scheduler');
    $at = time() + 3600;

    $scheduled = wp_schedule_single_event($at, 'pollora_int_plugin', [1], true);

    expect($scheduled)->toBeTrue()
        ->and(as_next_scheduled_action('pollora_int_plugin', [1], ActionSchedulerDriver::GROUP))->toBe($at)
        ->and(wp_next_scheduled('pollora_int_plugin', [1]))->toBe($at)
        ->and(wp_clear_scheduled_hook('pollora_int_plugin', [1], true))->toBe(1)
        ->and(as_has_scheduled_action('pollora_int_plugin', [1], ActionSchedulerDriver::GROUP))->toBeFalse()
        ->and(Cron::entries('pollora_int_plugin'))->toBe([]);
});

it('runs a due event through the queue runner of Action Scheduler and leaves the index', function (): void {
    Schedule::driver('action-scheduler');
    Schedule::once('pollora_int_as_due')->at(time() - 60)->with(['order' => 7])->call(RecordingJob::class);

    expect(as_has_scheduled_action('pollora_int_as_due', ['order' => 7], ActionSchedulerDriver::GROUP))->toBeTrue();

    ActionScheduler::runner()->run('Pollora integration');

    // Action Scheduler passes the values of the arguments, not their names
    $ran = RecordingJob::recorded();

    expect($ran)->toHaveCount(1)
        ->and(array_values($ran[0]))->toBe([7])
        ->and(as_get_scheduled_actions(['hook' => 'pollora_int_as_due', 'status' => ActionScheduler_Store::STATUS_COMPLETE, 'per_page' => -1], 'ids'))->toHaveCount(1)
        ->and(Cron::entries('pollora_int_as_due'))->toBe([]);
});
