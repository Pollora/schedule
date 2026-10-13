<?php

declare(strict_types=1);

namespace Pollora\Schedule\Migration;

use Pollora\Schedule\Contracts\DefersUntilReady;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;

/**
 * Moves the pending events of a driver to another: explicit, in both
 * directions, and safe to run again.
 *
 * An event is created in the target before it is taken out of the source, so a
 * crash in between duplicates it instead of losing it, and a second run finds
 * the event already there and does not create it twice. A recurring event keeps
 * the interval its entry stores: the theme that registered its schedule may not
 * be loaded where the migration runs (WP-CLI).
 */
final class Migrator
{
    public function __construct(private readonly ScheduleEngine $engine) {}

    /**
     * Events a driver still keeps, soonest first: the index's, and what the driver holds that the index does not show.
     *
     * @return list<Event>
     */
    public function pending(string $from): array
    {
        $events = CronIndex::events($from);

        if ($from !== CronIndex::WP_CRON) {
            $seen = [];

            foreach ($events as $event) {
                $seen[$this->identity($event)] = true;
            }

            foreach ($this->engine->driverNamed($from)->events() as $event) {
                if (! isset($seen[$this->identity($event)])) {
                    $events[] = new Event($event->hook, $event->timestamp, $event->recurrence, $event->args, $event->interval, $from);
                }
            }

            usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);
        }

        return $events;
    }

    /**
     * @throws ScheduleException When the drivers are the same, or the target cannot take events now
     */
    public function migrate(string $from, string $to, bool $dryRun = false): MigrationReport
    {
        if ($from === $to) {
            throw new ScheduleException(sprintf("Nothing to migrate: '%s' is both the source and the target.", $from));
        }

        $this->engine->driverNamed($from);
        $target = $this->engine->driverNamed($to);

        if (! $target->available()) {
            throw new ScheduleException(sprintf("The '%s' driver is not available here.", $to));
        }

        if ($target instanceof DefersUntilReady && ! $dryRun && ! $target->ready()) {
            throw new ScheduleException(sprintf("The '%s' driver is not ready yet: run the migration once WordPress has loaded it.", $to));
        }

        $moved = [];
        $skipped = [];
        $failed = [];
        $protected = $to === CronIndex::WP_CRON ? [] : $this->engine->protectedHooks();

        foreach ($this->pending($from) as $event) {
            if (in_array($event->hook, $protected, true)) {
                $skipped[] = ['event' => $event, 'reason' => 'protected hook, it stays on WP-Cron'];

                continue;
            }

            if ($dryRun) {
                $moved[] = $event;

                continue;
            }

            try {
                $this->move($event, $from, $to, $target);
                $moved[] = $event;
            } catch (\Throwable $throwable) {
                $failed[] = ['event' => $event, 'reason' => $throwable->getMessage()];
            }
        }

        return new MigrationReport($from, $to, $dryRun, $moved, $skipped, $failed);
    }

    private function move(Event $event, string $from, string $to, ScheduleDriver $target): void
    {
        $this->knowingTheSchedule($event, function () use ($event, $to, $target): void {
            if ($to === CronIndex::WP_CRON) {
                CronIndex::record($event, $to);

                return;
            }

            // Already there, from an earlier run: only the index and the source are left to settle
            $placed = $target->next($event->hook, $event->args) === null
                ? $this->engine->quietly(fn (): ?Event => $this->engine->hand($target, $event))
                : null;

            CronIndex::record($placed ?? $event, $to);
        });

        if ($from === CronIndex::WP_CRON) {
            CronIndex::forget($event->hook, $event->args, $from);

            return;
        }

        $this->engine->cancelOwned($from, $event->hook, $event->args);
    }

    /**
     * Run a change with the event's schedule registered, so a driver that looks the interval up by name finds it.
     */
    private function knowingTheSchedule(Event $event, \Closure $change): void
    {
        if ($event->recurrence === null || $event->interval === null || ! function_exists('add_filter')) {
            $change();

            return;
        }

        $register = static function (mixed $schedules) use ($event): array {
            $schedules = (array) $schedules;
            $schedules[$event->recurrence] ??= ['interval' => $event->interval, 'display' => $event->recurrence];

            return $schedules;
        };

        add_filter('cron_schedules', $register);

        try {
            $change();
        } finally {
            remove_filter('cron_schedules', $register);
        }
    }

    private function identity(Event $event): string
    {
        return $event->hook.'|'.md5(serialize($event->args)).'|'.$event->timestamp;
    }
}
