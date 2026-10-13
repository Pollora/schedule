<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Pollora\Schedule\Cli\Registrar;
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
     * The driver chosen before WordPress could take hooks, to wire once it can.
     */
    private static ?string $wanted = null;

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

            // WP-Cron by default still sets the guard: it must not run what another driver kept
            try {
                self::driver(is_string($name) && $name !== '' ? $name : 'wp-cron');
            } catch (InvalidArgumentException) {
                // A driver added with extend() later: the application chooses it then
                self::driver('wp-cron');
            }
        }

        self::boot();

        return self::$engine;
    }

    /**
     * Hook the chosen driver into WordPress, once WordPress can take hooks.
     *
     * An application that starts before WordPress (Pollora does) creates the
     * engine and chooses the driver first; this finishes the job as soon as
     * add_filter() exists, and is safe to call again.
     */
    public static function boot(): void
    {
        if (self::$wanted === null || ! function_exists('add_filter')) {
            return;
        }

        $name = self::$wanted;
        self::$wanted = null;
        self::driver($name);
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

        if (! function_exists('add_filter')) {
            self::$wanted = $name;

            return;
        }

        $engine->resolveDriversWith(static fn (string $driverName): ?ScheduleDriver => self::tryBuild($driverName));
        Registrar::register();

        if ($driver instanceof WpCronDriver) {
            $engine->useDriver($driver);

            // Events a driver kept before it was set aside still need the guard, and their driver reports to the index
            $engine->guardIfNeeded(static function (): iterable {
                foreach (self::DRIVERS as $driverName => $class) {
                    if ($driverName !== 'wp-cron' && is_subclass_of($class, ReportsChanges::class)) {
                        yield $driverName => self::build($driverName);
                    }
                }
            });

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
     * Whether a driver of this name can be chosen.
     */
    public static function knows(string $name): bool
    {
        return isset(self::$drivers[$name]) || isset(self::DRIVERS[$name]);
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
