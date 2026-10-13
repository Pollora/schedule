<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Pollora\Schedule\Contracts\ReportsChanges;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Testing\ScheduleFake;
use Psr\Container\ContainerInterface;

/**
 * Entry point to schedule events, one engine behind it.
 *
 *     Schedule::event('reports_compile')->daily()->at('02:00')->call([Reports::class, 'compile']);
 *     Schedule::once('close_poll')->at($poll->closesAt)->call(ClosePoll::class);
 *     Schedule::cancel('close_poll');
 *
 * The attribute #[Pollora\Attributes\Schedule] on a method reaches the same engine.
 */
final class Schedule
{
    private const array DRIVERS = [
        'wp-cron' => WpCronDriver::class,
        'action-scheduler' => ActionSchedulerDriver::class,
    ];

    private static ?ScheduleEngine $engine = null;

    /**
     * @var array<string, callable(): ScheduleDriver|ScheduleDriver>
     */
    private static array $drivers = [];

    private static ?ScheduleFake $fake = null;

    private static ?ScheduleDriver $driverBeforeFake = null;

    /**
     * A recurring event.
     */
    public static function event(string $hook): PendingEvent
    {
        return new PendingEvent(self::engine(), $hook, true);
    }

    /**
     * A single event.
     */
    public static function once(string $hook): PendingEvent
    {
        return new PendingEvent(self::engine(), $hook, false);
    }

    /**
     * Cancel the event of a hook that carries exactly these arguments.
     *
     * @param  array<int|string, mixed>  $args
     * @return int Number of events cancelled
     */
    public static function cancel(string $hook, array $args = []): int
    {
        return self::engine()->cancel($hook, $args);
    }

    /**
     * Cancel every event of a hook, whatever its arguments.
     */
    public static function clear(string $hook): int
    {
        return self::engine()->clear($hook);
    }

    /**
     * When the event with these arguments next runs, null when it is not scheduled.
     *
     * @param  array<int|string, mixed>  $args
     */
    public static function next(string $hook, array $args = []): ?DateTimeImmutable
    {
        $timestamp = self::engine()->next($hook, $args);

        return $timestamp === null ? null : (new DateTimeImmutable('@'.$timestamp))->setTimezone(When::timezone());
    }

    /**
     * Everything that is scheduled, whoever scheduled it.
     *
     * @return list<Event>
     */
    public static function events(): array
    {
        return self::engine()->events();
    }

    public static function engine(): ScheduleEngine
    {
        if (! self::$engine instanceof ScheduleEngine) {
            self::$engine = new ScheduleEngine;

            $name = getenv('POLLORA_SCHEDULE_DRIVER');

            if (is_string($name) && $name !== '') {
                self::driver($name);
            }
        }

        return self::$engine;
    }

    /**
     * Choose the driver of the site by name: 'wp-cron' (the default) or 'action-scheduler', or one added with extend().
     *
     * WordPress's own scheduling functions follow it, for the core, plugins and
     * themes as well. A driver that is not there falls back to WP-Cron, and
     * events already in the 'cron' option stay on WP-Cron until they are migrated.
     * POLLORA_SCHEDULE_DRIVER sets it without code.
     *
     * @throws InvalidArgumentException When no driver has this name
     */
    public static function driver(string $name): void
    {
        $driver = self::build($name);
        $engine = self::$engine ?? self::$engine = new ScheduleEngine;
        $engine->resolveDriversWith(static fn (string $driverName): ?ScheduleDriver => self::tryBuild($driverName));

        if ($driver instanceof WpCronDriver) {
            $engine->useDriver($driver);
            $engine->guard();

            // Events a driver kept before it was set aside still report to the index
            foreach (self::DRIVERS as $driverName => $class) {
                if ($driverName !== 'wp-cron' && is_subclass_of($class, ReportsChanges::class)) {
                    $engine->watch($driverName, self::build($driverName));
                }
            }

            return;
        }

        $engine->selectDriver($driver, $name);
    }

    /**
     * @throws InvalidArgumentException When no driver has this name
     */
    private static function build(string $name): ScheduleDriver
    {
        $factory = self::$drivers[$name] ?? (isset(self::DRIVERS[$name]) ? static fn (): ScheduleDriver => new (self::DRIVERS[$name]) : null) ?? throw new InvalidArgumentException(sprintf('Unknown schedule driver "%s".', $name));

        return $factory instanceof ScheduleDriver ? $factory : $factory();
    }

    private static function tryBuild(string $name): ?ScheduleDriver
    {
        try {
            return self::build($name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Add a driver, to choose by name with driver().
     *
     * @param  callable(): ScheduleDriver|ScheduleDriver  $driver
     */
    public static function extend(string $name, callable|ScheduleDriver $driver): void
    {
        self::$drivers[$name] = $driver;
    }

    /**
     * Use a driver in place of the default one (WP-Cron).
     */
    public static function useDriver(ScheduleDriver $driver): void
    {
        self::engine()->useDriver($driver);
    }

    /**
     * Build the classes passed to call() with this container.
     */
    public static function useContainer(?ContainerInterface $container): void
    {
        self::engine()->useContainer($container);
    }

    /**
     * Record events instead of scheduling them.
     */
    public static function fake(): ScheduleFake
    {
        if (self::$fake instanceof ScheduleFake) {
            return self::$fake;
        }

        self::$driverBeforeFake = self::engine()->driver();
        self::$fake = new ScheduleFake;
        self::engine()->useDriver(self::$fake);
        self::engine()->attachHandlers(false);

        return self::$fake;
    }

    /**
     * Back to the real driver after fake().
     */
    public static function reset(): void
    {
        if (self::$driverBeforeFake instanceof ScheduleDriver) {
            self::engine()->useDriver(self::$driverBeforeFake);
        }

        self::engine()->attachHandlers(true);
        self::$fake = null;
        self::$driverBeforeFake = null;
    }

    /**
     * @param  (callable(Event): bool)|null  $callback
     */
    public static function assertScheduled(string $hook, ?callable $callback = null): void
    {
        self::faking()->assertScheduled($hook, $callback);
    }

    public static function assertScheduledTimes(string $hook, int $times): void
    {
        self::faking()->assertScheduledTimes($hook, $times);
    }

    /**
     * @param  (callable(Event): bool)|null  $callback
     */
    public static function assertNotScheduled(string $hook, ?callable $callback = null): void
    {
        self::faking()->assertNotScheduled($hook, $callback);
    }

    public static function assertNothingScheduled(): void
    {
        self::faking()->assertNothingScheduled();
    }

    private static function faking(): ScheduleFake
    {
        return self::$fake ?? throw new LogicException('Call Schedule::fake() before asserting.');
    }
}
