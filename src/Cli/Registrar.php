<?php

declare(strict_types=1);

namespace Pollora\Schedule\Cli;

/**
 * Registers the WP-CLI commands of the package: an enriched `wp cron event`
 * and `wp pollora schedule migrate`. Does nothing outside WP-CLI.
 */
final class Registrar
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! defined('WP_CLI') || ! class_exists('WP_CLI')) {
            return;
        }

        self::$registered = true;

        // The core's command is bundled with WP-CLI; ours extends it and takes its place
        if (class_exists('Cron_Event_Command')) {
            \WP_CLI::add_command('cron event', CronEventCommand::class);
        }

        \WP_CLI::add_command('pollora schedule migrate', MigrateCommand::class);
    }
}
