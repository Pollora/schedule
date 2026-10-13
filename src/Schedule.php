<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use DateTimeImmutable;
use LogicException;
use Pollora\Schedule\Contracts\ScheduleDriver;
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
    private static ?ScheduleEngine $engine = null;

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
        return self::engine()->driver()->cancel($hook, $args);
    }

    /**
     * Cancel every event of a hook, whatever its arguments.
     */
    public static function clear(string $hook): int
    {
        return self::engine()->driver()->clear($hook);
    }

    /**
     * When the event with these arguments next runs, null when it is not scheduled.
     *
     * @param  array<int|string, mixed>  $args
     */
    public static function next(string $hook, array $args = []): ?DateTimeImmutable
    {
        $timestamp = self::engine()->driver()->next($hook, $args);

        return $timestamp === null ? null : (new DateTimeImmutable('@'.$timestamp))->setTimezone(When::timezone());
    }

    /**
     * Everything that is scheduled, whoever scheduled it.
     *
     * @return list<Event>
     */
    public static function events(): array
    {
        return self::engine()->driver()->events();
    }

    public static function engine(): ScheduleEngine
    {
        return self::$engine ??= new ScheduleEngine;
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
