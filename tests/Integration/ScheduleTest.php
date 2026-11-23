<?php

declare(strict_types=1);

use Pollora\Schedule\Interval;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\Tests\Integration\Support\Cron;
use Pollora\Schedule\Tests\Integration\Support\RecordingJob;

/**
 * What the Brain Monkey doubles cannot show: events written to, and run from, the real `cron` option by WordPress's
 * own scheduling functions, with the real timezone of the site and a real wp-cron.php run.
 */
beforeEach(function (): void {
    Cron::freshEngine();
    delete_option(RecordingJob::OPTION);
});

afterEach(function (): void {
    foreach (['pollora_int_tick', 'pollora_int_due', 'pollora_int_daily'] as $hook) {
        wp_unschedule_hook($hook);
        remove_all_actions($hook);
    }

    delete_option(RecordingJob::OPTION);
    delete_option('timezone_string');
    Cron::freshEngine();
});

it('writes a recurring event to the real cron option, with its interval, once however often it is declared', function (): void {
    $event = fn () => Schedule::event('pollora_int_tick')->every(new Interval(minutes: 15))->call(RecordingJob::class);

    $event();
    Cron::freshEngine();
    $event();

    $entries = Cron::entries('pollora_int_tick');
    $scheduled = wp_get_scheduled_event('pollora_int_tick');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['interval'])->toBe(900)
        ->and($scheduled)->toBeObject()
        ->and($scheduled->interval)->toBe(900)
        ->and(wp_get_schedules()[$scheduled->schedule]['interval'])->toBe(900)
        ->and(has_action('pollora_int_tick'))->toBeTrue()
        ->and(Schedule::next('pollora_int_tick')?->getTimestamp())->toBe($entries[0]['timestamp'])
        ->and(Schedule::cancel('pollora_int_tick'))->toBe(1)
        ->and(Cron::entries('pollora_int_tick'))->toBe([])
        ->and(wp_next_scheduled('pollora_int_tick'))->toBeFalse();
});

it('reads the time of day on the clock of the site', function (): void {
    update_option('timezone_string', 'Pacific/Auckland');

    Schedule::event('pollora_int_daily')->daily()->at('02:00')->call(RecordingJob::class);

    $next = Schedule::next('pollora_int_daily');

    expect($next->format('H:i'))->toBe('02:00')
        ->and($next->getTimezone()->getName())->toBe('Pacific/Auckland')
        ->and(Cron::entries('pollora_int_daily')[0]['timestamp'])->toBe($next->getTimestamp())
        ->and(Cron::entries('pollora_int_daily')[0]['schedule'])->toBe('daily');
});

it('runs a due event from wp-cron.php in another process, with its arguments, and takes it off the cron option', function (): void {
    $past = time() - 120;
    Schedule::once('pollora_int_due')->at($past)->with(['user' => 42, 'note' => 'hello'])->call(RecordingJob::class);

    expect(Cron::entries('pollora_int_due'))->toHaveCount(1);

    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/Support/cron-runner.php', 'pollora_int_due', '{"user":42,"note":"hello"}'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, $output)
        ->and(RecordingJob::recorded())->toBe([['user' => 42, 'note' => 'hello']])
        ->and(Cron::entries('pollora_int_due'))->toBe([]);
});
