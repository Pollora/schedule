<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

/**
 * Where scheduled events are kept and who runs them: WP-Cron, Action
 * Scheduler, a Laravel queue…
 *
 * The contract speaks WordPress: a hook, a timestamp, arguments and the key of
 * a recurrence the engine has registered in the 'cron_schedules' filter. A
 * driver never has to know about handlers; the hook fires when the event is
 * due, whoever runs it.
 */
interface ScheduleDriver
{
    /**
     * Whether the mechanism can be used in this request.
     */
    public function available(): bool;

    /**
     * Schedule a single event.
     *
     * @param  array<int|string, mixed>  $args
     * @return bool False when an identical event is already due: the call is ignored
     *
     * @throws ScheduleException When the event cannot be scheduled
     */
    public function once(string $hook, int $timestamp, array $args = []): bool;

    /**
     * Schedule a recurring event, first run at $timestamp.
     *
     * @param  string  $recurrence  Key of a WordPress schedule
     * @param  array<int|string, mixed>  $args
     *
     * @throws ScheduleException When the event cannot be scheduled
     */
    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool;

    /**
     * Cancel the events of a hook that carry exactly these arguments.
     *
     * @param  array<int|string, mixed>  $args
     * @return int Number of events cancelled
     */
    public function cancel(string $hook, array $args = []): int;

    /**
     * Cancel every event of a hook, whatever its arguments.
     *
     * @return int Number of events cancelled
     */
    public function clear(string $hook): int;

    /**
     * Timestamp of the next run of the event with these arguments, null when none is scheduled.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function next(string $hook, array $args = []): ?int;

    /**
     * Everything that is scheduled, soonest first.
     *
     * @return list<Event>
     */
    public function events(): array;
}
