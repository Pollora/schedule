<?php

declare(strict_types=1);

namespace Pollora\Schedule\Drivers;

use Pollora\Schedule\Contracts\DefersUntilReady;
use Pollora\Schedule\Contracts\FindsEvent;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

/**
 * Schedules through Action Scheduler: its queue, its retries, its Tools ›
 * Scheduled Actions screen.
 *
 * Action Scheduler is never required: the driver is available once a plugin
 * that bundles it is active. Every action it creates belongs to a group of its
 * own and is only looked up in that group, so cancelling a hook never reaches
 * the actions WooCommerce or another plugin keeps on the same hook.
 */
final class ActionSchedulerDriver implements DefersUntilReady, FindsEvent, ScheduleDriver
{
    public const string GROUP = 'pollora/schedule';

    /**
     * Window, in seconds, in which an identical single event is a duplicate: WordPress's own.
     */
    public const int DUPLICATE_WINDOW = 600;

    public function available(): bool
    {
        return function_exists('as_schedule_single_action') && function_exists('as_schedule_recurring_action');
    }

    public function ready(): bool
    {
        if (! $this->available()) {
            return false;
        }

        // Before 'action_scheduler_init', its functions exist but cannot store anything
        if (class_exists('ActionScheduler', false)) {
            return \ActionScheduler::is_initialized();
        }

        return function_exists('did_action') && did_action($this->readyAction()) > 0;
    }

    public function readyAction(): string
    {
        return 'action_scheduler_init';
    }

    public function once(string $hook, int $timestamp, array $args = []): bool
    {
        if ($this->hasDuplicate($hook, $timestamp, $args)) {
            return false;
        }

        if ((int) as_schedule_single_action($timestamp, $hook, $args, self::GROUP) === 0) {
            throw new ScheduleException(sprintf("Action Scheduler: the event '%s' could not be scheduled.", $hook));
        }

        return true;
    }

    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
    {
        $interval = (int) ((function_exists('wp_get_schedules') ? wp_get_schedules() : [])[$recurrence]['interval'] ?? 0);

        if ($interval <= 0) {
            throw new ScheduleException(sprintf("Action Scheduler: the event '%s' has no known recurrence '%s'.", $hook, $recurrence));
        }

        if ((int) as_schedule_recurring_action($timestamp, $interval, $hook, $args, self::GROUP) === 0) {
            throw new ScheduleException(sprintf("Action Scheduler: the event '%s' could not be scheduled.", $hook));
        }

        return true;
    }

    public function cancel(string $hook, array $args = []): int
    {
        return $this->cancelActions($this->pending($hook, $args));
    }

    public function clear(string $hook): int
    {
        return $this->cancelActions($this->pending($hook));
    }

    public function next(string $hook, array $args = []): ?int
    {
        $next = as_next_scheduled_action($hook, $args, self::GROUP);

        if ($next === true) {
            // Running, or waiting with no date: due now
            return time();
        }

        return $next === false ? null : (int) $next;
    }

    public function find(string $hook, array $args = []): ?Event
    {
        $actions = $this->pending($hook, $args);

        return $actions === [] ? null : $this->toEvent(reset($actions));
    }

    public function events(): array
    {
        $events = array_values(array_map($this->toEvent(...), $this->pending()));
        usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);

        return $events;
    }

    /**
     * Pending actions of the group, soonest first.
     *
     * @param  array<int|string, mixed>|null  $args  Exact arguments, null for any
     * @return array<int, object>
     */
    private function pending(?string $hook = null, ?array $args = null): array
    {
        $query = ['group' => self::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1, 'orderby' => 'date', 'order' => 'ASC'];

        if ($hook !== null) {
            $query['hook'] = $hook;
        }

        if ($args !== null) {
            $query['args'] = $args;
        }

        return as_get_scheduled_actions($query);
    }

    /**
     * @param  array<int, object>  $actions
     */
    private function cancelActions(array $actions): int
    {
        foreach (array_keys($actions) as $id) {
            \ActionScheduler::store()->cancel_action($id);
        }

        return count($actions);
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private function hasDuplicate(string $hook, int $timestamp, array $args): bool
    {
        $now = time();
        $min = $timestamp < $now + self::DUPLICATE_WINDOW ? 0 : $timestamp - self::DUPLICATE_WINDOW;
        $max = ($timestamp < $now ? $now : $timestamp) + self::DUPLICATE_WINDOW;

        foreach ($this->pending($hook, $args) as $action) {
            $date = $action->get_schedule()->get_date();

            if ($date !== null && $date->getTimestamp() >= $min && $date->getTimestamp() <= $max) {
                return true;
            }
        }

        return false;
    }

    private function toEvent(object $action): Event
    {
        $schedule = $action->get_schedule();
        $date = $schedule->get_date();
        $interval = method_exists($schedule, 'get_recurrence') && is_numeric($schedule->get_recurrence()) ? (int) $schedule->get_recurrence() : null;

        return new Event(
            hook: (string) $action->get_hook(),
            timestamp: $date === null ? time() : $date->getTimestamp(),
            recurrence: $interval === null ? null : self::recurrenceFor($interval),
            args: (array) $action->get_args(),
            interval: $interval,
        );
    }

    /**
     * Key of a registered schedule with this interval, the first when several share it.
     */
    private static function recurrenceFor(int $interval): string
    {
        foreach (function_exists('wp_get_schedules') ? wp_get_schedules() : [] as $key => $schedule) {
            if ((int) ($schedule['interval'] ?? 0) === $interval) {
                return (string) $key;
            }
        }

        return 'every_'.$interval.'_seconds';
    }
}
