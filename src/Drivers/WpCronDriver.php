<?php

declare(strict_types=1);

namespace Pollora\Schedule\Drivers;

use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

/**
 * Schedules through WP-Cron.
 *
 * A thin layer over WordPress's own functions: it never touches the 'cron'
 * option, so every filter WordPress and the site hang on those functions
 * ('pre_schedule_event', 'schedule_event'…) keeps seeing the events.
 */
final class WpCronDriver implements ScheduleDriver
{
    public function available(): bool
    {
        return function_exists('wp_schedule_event') && function_exists('wp_schedule_single_event');
    }

    public function once(string $hook, int $timestamp, array $args = []): bool
    {
        $result = wp_schedule_single_event($timestamp, $hook, $args, true);

        if (! self::failed($result)) {
            return true;
        }

        if (is_object($result) && method_exists($result, 'get_error_code') && $result->get_error_code() === 'duplicate_event') {
            return false;
        }

        throw new ScheduleException(sprintf("WP-Cron: the event '%s' could not be scheduled%s", $hook, self::reason($result)));
    }

    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
    {
        $result = wp_schedule_event($timestamp, $recurrence, $hook, $args, true);

        if (self::failed($result)) {
            throw new ScheduleException(sprintf("WP-Cron: the event '%s' could not be scheduled%s", $hook, self::reason($result)));
        }

        return true;
    }

    public function cancel(string $hook, array $args = []): int
    {
        return self::count(wp_clear_scheduled_hook($hook, $args, true));
    }

    public function clear(string $hook): int
    {
        return self::count(wp_unschedule_hook($hook, true));
    }

    public function next(string $hook, array $args = []): ?int
    {
        $next = wp_next_scheduled($hook, $args);

        return $next === false ? null : (int) $next;
    }

    public function events(): array
    {
        $events = [];

        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            if (! is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $hook => $entries) {
                foreach ((array) $entries as $entry) {
                    $events[] = new Event(
                        hook: (string) $hook,
                        timestamp: (int) $timestamp,
                        recurrence: ! empty($entry['schedule']) ? (string) $entry['schedule'] : null,
                        args: (array) ($entry['args'] ?? []),
                        interval: isset($entry['interval']) ? (int) $entry['interval'] : null,
                    );
                }
            }
        }

        usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);

        return $events;
    }

    /**
     * What the WordPress functions return when they remove events: a count, false or a WP_Error.
     */
    private static function count(mixed $result): int
    {
        return is_int($result) ? $result : 0;
    }

    /**
     * Whether WordPress refused the event: false or a WP_Error. A filter on
     * 'pre_schedule_event' (Cavalcade, Action Scheduler, the Laravel bridge) may
     * short-circuit with any other value, an object or an identifier, which counts as scheduled.
     */
    private static function failed(mixed $result): bool
    {
        return $result === false || (is_object($result) && method_exists($result, 'get_error_code'));
    }

    private static function reason(mixed $result): string
    {
        return is_object($result) && method_exists($result, 'get_error_message') ? ': '.$result->get_error_message() : '.';
    }
}
