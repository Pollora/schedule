<?php

declare(strict_types=1);

namespace Pollora\Schedule\Tests\Integration\Support;

use Pollora\Schedule\Schedule;
use ReflectionClass;

/**
 * The `cron` option as the tests read it.
 */
final class Cron
{
    /**
     * Starts the engine from nothing, as a new request does.
     */
    public static function freshEngine(): void
    {
        $schedule = new ReflectionClass(Schedule::class);

        foreach (['engine', 'fake', 'driverBeforeFake', 'wanted'] as $property) {
            $schedule->setStaticPropertyValue($property, null);
        }
    }

    /**
     * The entries of a hook, read again from the database.
     *
     * @return list<array{timestamp: int, schedule: string|false, args: array<int|string, mixed>, interval?: int, driver?: string}>
     */
    public static function entries(string $hook): array
    {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('cron', 'options');
        $entries = [];

        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ($hooks[$hook] ?? [] as $event) {
                $entries[] = ['timestamp' => (int) $timestamp, ...$event];
            }
        }

        return $entries;
    }
}
