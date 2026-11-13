<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Cli\MigrateCommand;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';
require_once __DIR__.'/../Support/WpCliFake.php';

const MIGRATE_AT = 4_000_000_000;

beforeEach(function (): void {
    WP_CLI::reset();

    $this->cron = [];
    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('add_action')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_unschedule_event')->justReturn(true);
    Functions\when('wp_schedule_single_event')->justReturn(true);
    Functions\when('wp_schedule_event')->justReturn(true);

    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver(new ActionSchedulerDriver);
    (new ReflectionClass(Schedule::class))->setStaticPropertyValue('engine', $this->engine);
});

it('moves the events of WP-Cron to the driver of the site, and keeps the protected hooks where they are', function (): void {
    $this->cron = [MIGRATE_AT => [
        'digest' => [md5(serialize([])) => ['schedule' => false, 'args' => []]],
        'action_scheduler_run_queue' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => [], 'interval' => 86400]],
    ]];

    (new MigrateCommand)([], []);

    expect(WP_CLI::$logs)->toBe([
        'Moved digest (once)',
        'Kept action_scheduler_run_queue: protected hook, it stays on WP-Cron',
    ])
        ->and(WP_CLI::$successes)->toBe(['Moved 1 event(s) from wp-cron to action-scheduler.'])
        ->and(ActionScheduler_FakeStore::$actions)->toHaveCount(1)
        ->and(CronIndex::ownerOf('digest'))->toBe('action-scheduler');
});

it('moves nothing with --dry-run and says so', function (): void {
    $this->cron = [MIGRATE_AT => ['reports' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => [], 'interval' => 86400]]]];

    (new MigrateCommand)([], ['dry-run' => true, 'to' => 'action-scheduler']);

    expect(WP_CLI::$logs)->toBe(['Would move reports (daily)'])
        ->and(WP_CLI::$successes)->toBe(['Would move 1 event(s) from wp-cron to action-scheduler (dry run).'])
        ->and(ActionScheduler_FakeStore::$actions)->toBe([])
        ->and(CronIndex::hasMarked())->toBeFalse();
});

it('migrates back to WP-Cron from the driver that still keeps events when no source is given', function (): void {
    CronIndex::record(new Event('reports', MIGRATE_AT, 'daily', [], 86400), 'action-scheduler');

    (new MigrateCommand)([], ['to' => 'wp-cron', 'dry-run' => '1']);

    expect(WP_CLI::$logs)->toBe(['Would move reports (daily)'])
        ->and(WP_CLI::$successes)->toBe(['Would move 1 event(s) from action-scheduler to wp-cron (dry run).']);
});

it('moves back to WP-Cron for real, taking the event out of the driver', function (): void {
    $this->engine->place(new Event('reports', MIGRATE_AT, 'daily'));

    (new MigrateCommand)([], ['to' => 'wp-cron']);

    expect(WP_CLI::$logs)->toBe(['Moved reports (daily)'])
        ->and(WP_CLI::$successes)->toBe(['Moved 1 event(s) from action-scheduler to wp-cron.'])
        ->and(array_filter(ActionScheduler_FakeStore::$actions, fn ($a) => $a->status === 'pending'))->toBe([])
        ->and(CronIndex::ownerOf('reports'))->toBe('wp-cron');
});

it('asks for --from when no other driver keeps events to move back to WP-Cron', function (): void {
    expect(fn () => (new MigrateCommand)([], ['to' => 'wp-cron']))
        ->toThrow(RuntimeException::class, 'No other driver keeps events: say which one with --from.');
});

it('reports the reason when the migration cannot start', function (): void {
    expect(fn () => (new MigrateCommand)([], ['from' => 'wp-cron', 'to' => 'wp-cron']))
        ->toThrow(RuntimeException::class, "Nothing to migrate: 'wp-cron' is both the source and the target.")
        ->and(fn () => (new MigrateCommand)([], ['to' => 'nowhere']))
        ->toThrow(RuntimeException::class, "No schedule driver is named 'nowhere'.");
});

it('warns about each event it could not move and ends in error', function (): void {
    $this->cron = [MIGRATE_AT => ['reports' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => [], 'interval' => 86400]]]];
    $this->engine->selectDriver(new class implements ScheduleDriver
    {
        public function available(): bool
        {
            return true;
        }

        public function once(string $hook, int $timestamp, array $args = []): bool
        {
            throw new RuntimeException('the queue refused it');
        }

        public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
        {
            throw new RuntimeException('the queue refused it');
        }

        public function cancel(string $hook, array $args = []): int
        {
            return 0;
        }

        public function clear(string $hook): int
        {
            return 0;
        }

        public function next(string $hook, array $args = []): ?int
        {
            return null;
        }

        public function events(): array
        {
            return [];
        }
    }, 'broken');

    try {
        (new MigrateCommand)([], []);
        $thrown = null;
    } catch (RuntimeException $exception) {
        $thrown = $exception->getMessage();
    }

    expect(WP_CLI::$warnings)->toHaveCount(1)
        ->and(WP_CLI::$warnings[0])->toBe('Could not move reports: the queue refused it')
        ->and($thrown)->toBe('Moved 0 event(s) from wp-cron to broken. Some events stayed where they were.');
});
