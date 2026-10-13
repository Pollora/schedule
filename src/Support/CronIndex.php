<?php

declare(strict_types=1);

namespace Pollora\Schedule\Support;

use Pollora\Schedule\Event;

/**
 * The 'cron' option as an index of every scheduled event, whoever runs it.
 *
 * WordPress reads the option without a query (it is autoloaded), and WP Crontrol,
 * Query Monitor, Site Health and `wp cron event list` read it too. An event kept
 * by another driver is written there with a 'driver' key next to 'schedule' and
 * 'args'; an entry without the key is WP-Cron's own, so what the core writes
 * needs no change.
 *
 * Writes go through _get_cron_array() and _set_cron_array(), which keep the
 * option's 'version'. The writing flag lets the engine's own filter on the option
 * tell its writes from everybody else's.
 */
final class CronIndex
{
    public const string MARKER = 'driver';

    public const string WP_CRON = 'wp-cron';

    private static bool $writing = false;

    public static function writing(): bool
    {
        return self::$writing;
    }

    /**
     * The driver that keeps the event, null when the index has no such event.
     *
     * @param  array<int|string, mixed>  $args
     */
    public static function ownerOf(string $hook, array $args = []): ?string
    {
        $key = self::key($args);

        foreach (self::read() as $hooks) {
            if (is_array($hooks) && isset($hooks[$hook][$key]) && is_array($hooks[$hook][$key])) {
                return self::owner($hooks[$hook][$key]);
            }
        }

        return null;
    }

    /**
     * Drivers that keep events of a hook, each once.
     *
     * @param  array<int|string, mixed>|null  $args  Exact arguments, null for any
     * @return list<string>
     */
    public static function ownersOf(string $hook, ?array $args = null): array
    {
        $key = $args === null ? null : self::key($args);
        $owners = [];

        foreach (self::read() as $hooks) {
            foreach (is_array($hooks) ? (array) ($hooks[$hook] ?? []) : [] as $signature => $entry) {
                if (is_array($entry) && ($key === null || $key === $signature)) {
                    $owners[self::owner($entry)] = true;
                }
            }
        }

        return array_keys($owners);
    }

    /**
     * Whether the index holds an event that a driver other than WP-Cron keeps.
     */
    public static function hasMarked(): bool
    {
        foreach (self::read() as $hooks) {
            foreach (is_array($hooks) ? $hooks : [] as $entries) {
                foreach ((array) $entries as $entry) {
                    if (is_array($entry) && self::owner($entry) !== self::WP_CRON) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Number of events a driver other than WP-Cron keeps, for a hook and optionally exact arguments.
     *
     * @param  array<int|string, mixed>|null  $args
     */
    public static function countMarked(string $hook, ?array $args = null): int
    {
        return count(array_filter(self::events(), static fn (Event $event): bool => $event->driver !== self::WP_CRON && $event->hook === $hook && ($args === null || $event->args === $args)));
    }

    /**
     * The events of the index, soonest first.
     *
     * @param  string|null  $owner  Only those a driver keeps, all by default
     * @return list<Event>
     */
    public static function events(?string $owner = null): array
    {
        $events = [];

        foreach (self::read() as $timestamp => $hooks) {
            if (! is_array($hooks) || ! is_int($timestamp)) {
                continue;
            }

            foreach ($hooks as $hook => $entries) {
                foreach ((array) $entries as $entry) {
                    if (! is_array($entry) || ($owner !== null && self::owner($entry) !== $owner)) {
                        continue;
                    }

                    $events[] = new Event(
                        hook: (string) $hook,
                        timestamp: $timestamp,
                        recurrence: ! empty($entry['schedule']) ? (string) $entry['schedule'] : null,
                        args: (array) ($entry['args'] ?? []),
                        interval: isset($entry['interval']) ? (int) $entry['interval'] : null,
                        driver: self::owner($entry),
                    );
                }
            }
        }

        usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);

        return $events;
    }

    /**
     * Write the event as kept by a driver; the one it replaces, if any, goes.
     *
     * A recurring event keeps the name of its schedule when the interval is the same: a driver only knows the interval.
     */
    public static function record(Event $event, string $owner): void
    {
        $crons = self::read(fresh: true);
        $key = self::key($event->args);
        $schedule = $event->recurrence;
        $interval = $event->interval;

        if ($event->recurrence !== null) {
            $interval ??= (int) ((function_exists('wp_get_schedules') ? wp_get_schedules() : [])[$event->recurrence]['interval'] ?? 0);
        }

        foreach (self::strip($crons, $event->hook, $key, $owner) as $previous) {
            if ($schedule !== null && ($previous['interval'] ?? null) === $interval && ! empty($previous['schedule'])) {
                $schedule = (string) $previous['schedule'];
            }
        }

        $entry = ['schedule' => $schedule ?? false, 'args' => $event->args];

        if ($schedule !== null) {
            $entry['interval'] = $interval;
        }

        if ($owner !== self::WP_CRON) {
            $entry[self::MARKER] = $owner;
        }

        $crons[$event->timestamp][$event->hook][$key] = $entry;
        ksort($crons);

        self::write($crons);
    }

    /**
     * Take the events of a hook with these arguments, a driver keeps, out of the index.
     *
     * @param  array<int|string, mixed>  $args
     */
    public static function forget(string $hook, array $args, string $owner): void
    {
        $crons = self::read(fresh: true);

        if (self::strip($crons, $hook, self::key($args), $owner) !== []) {
            self::write($crons);
        }
    }

    /**
     * Take every event of a hook a driver keeps out of the index.
     */
    public static function forgetHook(string $hook, string $owner): void
    {
        $crons = self::read(fresh: true);
        $found = false;

        foreach ($crons as $timestamp => $hooks) {
            foreach (is_array($hooks) ? (array) ($hooks[$hook] ?? []) : [] as $key => $entry) {
                if (is_array($entry) && self::owner($entry) === $owner) {
                    unset($crons[$timestamp][$hook][$key]);
                    $found = true;
                }
            }

            self::prune($crons, $timestamp, $hook);
        }

        if ($found) {
            self::write($crons);
        }
    }

    /**
     * What is ready to run: the entries a driver other than WP-Cron keeps are not WP-Cron's to run.
     *
     * @return array<int, array<string, array<string, array<string, mixed>>>>|null Null when nothing is marked, so the core reads the option itself
     */
    public static function ready(): ?array
    {
        $crons = self::read();
        $marked = false;

        foreach ($crons as $timestamp => $hooks) {
            foreach (is_array($hooks) ? $hooks : [] as $hook => $entries) {
                foreach ((array) $entries as $key => $entry) {
                    if (is_array($entry) && self::owner($entry) !== self::WP_CRON) {
                        unset($crons[$timestamp][$hook][$key]);
                        $marked = true;
                    }
                }

                self::prune($crons, $timestamp, $hook);
            }
        }

        if (! $marked) {
            return null;
        }

        $now = microtime(true);
        $ready = [];

        foreach ($crons as $timestamp => $hooks) {
            if (is_int($timestamp) && $timestamp <= $now) {
                $ready[$timestamp] = $hooks;
            }
        }

        return $ready;
    }

    /**
     * What a direct write to the option does to the events a driver keeps.
     *
     * @param  array<int|string, mixed>  $old
     * @param  array<int|string, mixed>  $new
     * @return array{removed: list<Event>, moved: list<Event>} The events that left the index, and those that are now at another time (at their new time)
     */
    public static function changes(array $old, array $new): array
    {
        $before = self::marked($old);
        $after = self::marked($new);
        $changes = ['removed' => [], 'moved' => []];

        foreach ($before as $identity => $event) {
            if (! isset($after[$identity])) {
                $changes['removed'][] = $event;
            } elseif ($after[$identity]->timestamp !== $event->timestamp) {
                $changes['moved'][] = $after[$identity];
            }
        }

        return $changes;
    }

    /**
     * @param  array<int|string, mixed>  $crons
     * @return array<string, Event>
     */
    private static function marked(array $crons): array
    {
        $marked = [];

        foreach ($crons as $timestamp => $hooks) {
            if (! is_array($hooks) || ! is_int($timestamp)) {
                continue;
            }

            foreach ($hooks as $hook => $entries) {
                foreach ((array) $entries as $key => $entry) {
                    if (! is_array($entry) || self::owner($entry) === self::WP_CRON) {
                        continue;
                    }

                    $marked[self::owner($entry).'|'.$hook.'|'.$key] = new Event(
                        hook: (string) $hook,
                        timestamp: $timestamp,
                        recurrence: ! empty($entry['schedule']) ? (string) $entry['schedule'] : null,
                        args: (array) ($entry['args'] ?? []),
                        interval: isset($entry['interval']) ? (int) $entry['interval'] : null,
                        driver: self::owner($entry),
                    );
                }
            }
        }

        return $marked;
    }

    /**
     * Remove the entries a driver keeps for a hook and an argument key; returns them.
     *
     * @param  array<int|string, mixed>  $crons
     * @return list<array<string, mixed>>
     */
    private static function strip(array &$crons, string $hook, string $key, string $owner): array
    {
        $removed = [];

        foreach ($crons as $timestamp => $hooks) {
            if (is_array($hooks) && isset($hooks[$hook][$key]) && is_array($hooks[$hook][$key]) && self::owner($hooks[$hook][$key]) === $owner) {
                $removed[] = $hooks[$hook][$key];
                unset($crons[$timestamp][$hook][$key]);
                self::prune($crons, $timestamp, $hook);
            }
        }

        return $removed;
    }

    /**
     * @param  array<int|string, mixed>  $crons
     */
    private static function prune(array &$crons, int|string $timestamp, int|string $hook): void
    {
        if (isset($crons[$timestamp][$hook]) && $crons[$timestamp][$hook] === []) {
            unset($crons[$timestamp][$hook]);
        }

        if (isset($crons[$timestamp]) && $crons[$timestamp] === []) {
            unset($crons[$timestamp]);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function owner(array $entry): string
    {
        return (string) ($entry[self::MARKER] ?? self::WP_CRON);
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private static function key(array $args): string
    {
        return md5(serialize($args));
    }

    /**
     * The option's entries.
     *
     * Without a persistent object cache, the copy of an autoloaded option is the
     * one taken when the request started: a long request (Action Scheduler's
     * queue runner) that wrote from it would undo what other requests scheduled
     * since. A write reads again from the database first, which leaves the
     * window of the write itself.
     *
     * @param  bool  $fresh  Read from the database, before a change
     * @return array<int|string, mixed>
     */
    private static function read(bool $fresh = false): array
    {
        if ($fresh && function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('cron', 'options');
        }

        $crons = _get_cron_array();

        return is_array($crons) ? $crons : [];
    }

    /**
     * @param  array<int|string, mixed>  $crons
     */
    private static function write(array $crons): void
    {
        self::$writing = true;

        try {
            _set_cron_array($crons);
        } finally {
            self::$writing = false;
        }
    }
}
