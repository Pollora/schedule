<?php

declare(strict_types=1);

namespace Pollora\Schedule\Support;

use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\ScheduleEngine;

/**
 * Makes WordPress's scheduling functions follow the chosen driver, through the
 * 'pre_*' filters of wp-includes/cron.php, so the core, plugins and themes
 * that call wp_schedule_event() need no change.
 *
 * Routing is the engine's, from the 'cron' option, which indexes every event
 * with the driver that keeps it: an event WP-Cron keeps stays on it (the filter
 * returns null and the core carries on), what is new goes to the chosen driver.
 * The interceptor never calls WP-Cron's own driver, and where it has to call a
 * core function again it raises a flag so its own filters step aside.
 *
 * Two filters are not about routing and stay whatever the driver: WP-Cron never
 * runs an event another driver keeps, and a direct write to the option reaches
 * the driver of the events it changes.
 */
final class CronInterceptor
{
    private const array ROUTING = [
        'pre_schedule_event' => ['scheduleEvent', 3],
        'pre_clear_scheduled_hook' => ['clearScheduledHook', 4],
        'pre_unschedule_hook' => ['unscheduleHook', 3],
    ];

    private const array GUARD = [
        'pre_get_ready_cron_jobs' => ['readyJobs', 1],
        'pre_update_option_cron' => ['updateOption', 3],
        'pre_reschedule_event' => ['rescheduleEvent', 3],
        'pre_unschedule_event' => ['unscheduleEvent', 5],
    ];

    private bool $installed = false;

    private bool $stepsAside = false;

    private bool $routing = true;

    /**
     * Events a runner rescheduled and has yet to unschedule at their old time, by hook and arguments.
     *
     * @var array<string, int>
     */
    private array $rescheduled = [];

    /**
     * @var array<string, \Closure>
     */
    private array $callbacks = [];

    public function __construct(private readonly ScheduleEngine $engine) {}

    public function install(bool $routing = true): void
    {
        if ($this->installed) {
            return;
        }

        $this->installed = true;
        $this->routing = $routing;

        foreach ([...($routing ? self::ROUTING : []), ...self::GUARD] as $filter => [$method, $arguments]) {
            $this->callbacks[$filter] = $this->{$method}(...);
            add_filter($filter, $this->callbacks[$filter], 10, $arguments);
        }
    }

    public function uninstall(): void
    {
        foreach ($this->callbacks as $filter => $callback) {
            remove_filter($filter, $callback, 10);
        }

        $this->callbacks = [];
        $this->installed = false;
    }

    /**
     * What WP-Cron may run: not the events another driver keeps.
     *
     * @return array<int, mixed>|null
     */
    private function readyJobs(mixed $pre): mixed
    {
        return $pre ?? CronIndex::ready();
    }

    /**
     * A direct write to the option (a plugin that rebuilds the array, WP Crontrol) changes events a driver keeps: the driver follows.
     *
     * @return mixed The value, untouched
     */
    private function updateOption(mixed $value, mixed $old = null): mixed
    {
        if (CronIndex::writing() || ! is_array($value) || ! is_array($old)) {
            return $value;
        }

        $changes = CronIndex::changes($old, $value);

        if ($changes['removed'] !== [] || $changes['moved'] !== []) {
            $this->engine->applyChanges($changes);
        }

        return $value;
    }

    /**
     * @param  mixed  $pre  What an earlier filter returned
     * @return bool|\WP_Error|null
     */
    private function scheduleEvent(mixed $pre, object $event, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $args = (array) $event->args;

        if ($this->engine->driverFor($event->hook, $args) === $this->engine->stores()[1]) {
            return null;
        }

        try {
            $placed = $this->engine->place(new Event(
                hook: (string) $event->hook,
                timestamp: (int) $event->timestamp,
                recurrence: empty($event->schedule) ? null : (string) $event->schedule,
                args: $args,
                interval: isset($event->interval) ? (int) $event->interval : null,
            ));
        } catch (\Throwable $throwable) {
            return $wpError ? new \WP_Error('schedule_error', $throwable->getMessage()) : false;
        }

        if (! $placed) {
            return $wpError ? new \WP_Error('duplicate_event', 'A duplicate event already exists.') : false;
        }

        return true;
    }

    /**
     * Rescheduling is the runner's job: WP-Cron reschedules what it runs, Action Scheduler its recurring actions.
     *
     * A runner that follows WP-Cron's way (wp cron event run, WP Crontrol)
     * reschedules the event, then unschedules it at its old time: for an event
     * another driver keeps, the second step must not cancel what the first said
     * was kept, so the pair is remembered.
     *
     * @return bool|\WP_Error|null
     */
    private function rescheduleEvent(mixed $pre, object $event): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $args = (array) $event->args;
        $owner = CronIndex::ownerOf((string) $event->hook, $args);

        if ($owner !== null && $owner !== CronIndex::WP_CRON) {
            $this->rescheduled[self::identity((string) $event->hook, $args)] = (int) $event->timestamp;

            return true;
        }

        if (! $this->routing) {
            return null;
        }

        return $this->engine->driverFor($event->hook, $args) === $this->engine->stores()[1] ? null : true;
    }

    /**
     * @return bool|\WP_Error|null
     */
    private function unscheduleEvent(mixed $pre, int $timestamp, string $hook, array $args): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $owner = CronIndex::ownerOf($hook, $args);

        if ($owner === CronIndex::WP_CRON || ($owner === null && ! $this->routing)) {
            return null;
        }

        if (($this->rescheduled[self::identity($hook, $args)] ?? null) === $timestamp) {
            unset($this->rescheduled[self::identity($hook, $args)]);

            return true;
        }

        if ($owner === null) {
            // Not in the index: an event the chosen driver holds without having written it
            $driver = $this->engine->stores()[0];

            if ($driver->next($hook, $args) !== $timestamp) {
                return null;
            }

            $driver->cancel($hook, $args);

            return true;
        }

        $indexed = array_filter(CronIndex::events($owner), static fn (Event $event): bool => $event->hook === $hook && $event->args === $args && $event->timestamp === $timestamp);

        if ($indexed === []) {
            return null;
        }

        try {
            $this->engine->cancelOwned($owner, $hook, $args);
        } catch (ScheduleException) {
            return null;
        }

        return true;
    }

    /**
     * @return int|false|\WP_Error|null
     */
    private function clearScheduledHook(mixed $pre, string $hook, array $args, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $indexed = CronIndex::countMarked($hook, $args);
        $cancelled = $this->engine->quietly(fn (): int => $this->engine->stores()[0]->cancel($hook, $args));

        return $this->withCore(static fn () => wp_clear_scheduled_hook($hook, $args, $wpError), max(0, $cancelled - $indexed));
    }

    /**
     * @return int|false|\WP_Error|null
     */
    private function unscheduleHook(mixed $pre, string $hook, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $indexed = CronIndex::countMarked($hook);
        $cancelled = $this->engine->quietly(fn (): int => $this->engine->stores()[0]->clear($hook));

        return $this->withCore(static fn () => wp_unschedule_hook($hook, $wpError), max(0, $cancelled - $indexed));
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private static function identity(string $hook, array $args): string
    {
        return $hook.'|'.md5(serialize($args));
    }

    /**
     * Run the core function for what is in the 'cron' option and add what the driver cancelled and the option does not show.
     */
    private function withCore(\Closure $core, int $cancelled): mixed
    {
        $this->stepsAside = true;

        try {
            $result = $core();
        } finally {
            $this->stepsAside = false;
        }

        if (is_int($result)) {
            return $result + $cancelled;
        }

        return $result === false && $cancelled > 0 ? $cancelled : $result;
    }
}
