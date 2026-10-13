<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Support\Str;
use Pollora\Attributes\Schedule;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\Schedule\Every;
use Pollora\Schedule\Infrastructure\Services\ScheduleDiscovery;
use Pollora\Schedule\Interval;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

final class NightlyReports
{
    /** @var list<string> */
    public static array $calls = [];

    #[Schedule(Every::DAY)]
    public function compile(): void
    {
        self::$calls[] = 'compile';
    }

    #[Schedule('hourly', hook: 'reports_ping', args: ['site' => 1])]
    public function ping(array $args): void
    {
        self::$calls[] = 'ping:'.$args['site'];
    }

    #[Schedule(Every::MONTH)]
    public function archiveOldReports(): void
    {
        self::$calls[] = 'archiveOldReports';
    }

    #[Schedule(new Interval(minutes: 15, display: 'Every quarter hour'))]
    public function refresh(): void
    {
        self::$calls[] = 'refresh';
    }
}

beforeEach(function (): void {
    NightlyReports::$calls = [];
    $this->hooks = [];
    $this->filters = [];
    $this->events = [];

    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->hooks[$hook] = $callback;

        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook][] = $callback;

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_schedule_event')->alias(function (int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        $this->events[$hook] = [$recurrence, $args];

        return true;
    });

    $discovery = new ScheduleDiscovery;
    $discovery->discover(new DiscoveryLocation('', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(NightlyReports::class)), new ReflectionCache);
    $discovery->apply();
});

it('schedules each method on its hook, named after the class and method by default', function (): void {
    expect($this->events)->toHaveKeys(['nightly_reports_compile', 'reports_ping', 'nightly_reports_archive_old_reports'])
        ->and($this->events['nightly_reports_compile'])->toBe(['daily', []])
        ->and($this->events['reports_ping'])->toBe(['hourly', ['site' => 1]])
        ->and($this->events['nightly_reports_archive_old_reports'])->toBe(['every_month', []]);
});

it('registers the schedules WordPress does not know', function (): void {
    $schedules = array_reduce($this->filters['cron_schedules'], fn (array $schedules, callable $filter): array => $filter($schedules), []);

    expect($schedules['every_month'])->toBe(['interval' => 30 * 86400, 'display' => 'Once Monthly'])
        ->and($schedules)->toContain(['interval' => 900, 'display' => 'Every quarter hour']);
});

it('runs the method, with its arguments, when the hook fires', function (): void {
    ($this->hooks['nightly_reports_compile'])();
    ($this->hooks['reports_ping'])();

    expect(NightlyReports::$calls)->toBe(['compile', 'ping:1']);
});

it('derives the hook name as Str::snake() does, so scheduled hooks keep their name', function (string $class, string $method): void {
    $hookName = (new ReflectionMethod(ScheduleDiscovery::class, 'generateHookName'))->invoke(new ScheduleDiscovery, $class, $method);

    expect($hookName)->toBe(Str::snake(class_basename($class)).'_'.Str::snake($method));
})->with([
    ['App\\Cron\\NightlyReports', 'compile'],
    ['App\\Cron\\HTMLSitemap', 'rebuildXMLIndex'],
    ['Reports', 'sendTop10'],
    ['App\\Cron\\ÉvénementsPublics', 'purge'],
]);
