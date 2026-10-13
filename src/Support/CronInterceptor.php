<?php

declare(strict_types=1);

namespace Pollora\Schedule\Support;

use Pollora\Schedule\Contracts\FindsEvent;
use Pollora\Schedule\Event;
use Pollora\Schedule\ScheduleEngine;

/**
 * Makes WordPress's scheduling functions follow the chosen driver, through the
 * 'pre_*' filters of wp-includes/cron.php, so the core, plugins and themes
 * that call wp_schedule_event() need no change.
 *
 * Routing is the engine's: what is already in the 'cron' option stays on
 * WP-Cron (the filter returns null and the core carries on), what is new goes
 * to the driver. The interceptor never calls WP-Cron's own driver, and where it
 * has to call a core function again it raises a flag so its own filters step aside.
 */
final class CronInterceptor
{
    private const array FILTERS = [
        'pre_schedule_event' => ['scheduleEvent', 3],
        'pre_reschedule_event' => ['rescheduleEvent', 3],
        'pre_unschedule_event' => ['unscheduleEvent', 5],
        'pre_clear_scheduled_hook' => ['clearScheduledHook', 4],
        'pre_unschedule_hook' => ['unscheduleHook', 3],
        'pre_get_scheduled_event' => ['getScheduledEvent', 4],
    ];

    private bool $installed = false;

    private bool $stepsAside = false;

    /**
     * @var array<string, \Closure>
     */
    private array $callbacks = [];

    public function __construct(private readonly ScheduleEngine $engine) {}

    public function install(): void
    {
        if ($this->installed) {
            return;
        }

        $this->installed = true;

        foreach (self::FILTERS as $filter => [$method, $arguments]) {
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
     * @return bool|\WP_Error|null
     */
    private function rescheduleEvent(mixed $pre, object $event, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $args = (array) $event->args;

        return $this->engine->driverFor($event->hook, $args) === $this->engine->stores()[1] ? null : true;
    }

    /**
     * @return bool|\WP_Error|null
     */
    private function unscheduleEvent(mixed $pre, int $timestamp, string $hook, array $args, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $driver = $this->engine->stores()[0];

        if ($this->engine->inCronOption($hook, $args) || $driver->next($hook, $args) !== $timestamp) {
            return null;
        }

        $driver->cancel($hook, $args);

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

        $cancelled = $this->engine->stores()[0]->cancel($hook, $args);

        return $this->withCore(static fn () => wp_clear_scheduled_hook($hook, $args, $wpError), $cancelled);
    }

    /**
     * @return int|false|\WP_Error|null
     */
    private function unscheduleHook(mixed $pre, string $hook, bool $wpError = false): mixed
    {
        if ($pre !== null || $this->stepsAside) {
            return $pre;
        }

        $cancelled = $this->engine->stores()[0]->clear($hook);

        return $this->withCore(static fn () => wp_unschedule_hook($hook, $wpError), $cancelled);
    }

    /**
     * An event in the 'cron' option is the core's to describe; null, not false, when the driver has none, or the core would never read the option.
     *
     * @return object|null
     */
    private function getScheduledEvent(mixed $pre, string $hook, array $args, mixed $timestamp = null): mixed
    {
        $driver = $this->engine->stores()[0];

        if ($pre !== null || $this->stepsAside || ! $driver instanceof FindsEvent || $this->engine->inCronOption($hook, $args)) {
            return $pre;
        }

        $found = $driver->find($hook, $args);

        if (! $found instanceof Event || ($timestamp !== null && (int) $timestamp !== $found->timestamp)) {
            return null;
        }

        $event = (object) ['hook' => $found->hook, 'timestamp' => $found->timestamp, 'schedule' => $found->recurrence ?? false, 'args' => $found->args];

        if ($found->interval !== null) {
            $event->interval = $found->interval;
        }

        return $event;
    }

    /**
     * Run the core function for what is in the 'cron' option and add what the driver cancelled.
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
