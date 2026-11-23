<?php

declare(strict_types=1);

namespace Pollora\Schedule\Tests\Integration\Support;

/**
 * A handler that leaves a trace in the database, which another process can read: the cron runner is a process of its own.
 */
final class RecordingJob
{
    public const string OPTION = 'pollora_schedule_integration_ran';

    /**
     * @param  array<int|string, mixed>  $args  What the event carried
     */
    public function __invoke(array $args = []): void
    {
        $ran = get_option(self::OPTION, []);
        $ran = is_array($ran) ? $ran : [];
        $ran[] = $args;

        update_option(self::OPTION, $ran, false);
    }

    /**
     * What the runs left, read from the table: no cache of this process stands between the test and a process that wrote it.
     *
     * @return list<array<int|string, mixed>>
     */
    public static function recorded(): array
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        $ran = is_string($value) ? maybe_unserialize($value) : [];

        return is_array($ran) ? array_values($ran) : [];
    }
}
