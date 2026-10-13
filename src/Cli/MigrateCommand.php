<?php

declare(strict_types=1);

namespace Pollora\Schedule\Cli;

use Pollora\Schedule\Migration\MigrationReport;
use Pollora\Schedule\Migration\Migrator;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\Support\CronIndex;

/**
 * Moves the pending events of a driver to another.
 *
 * ## EXAMPLES
 *
 *     # See what would move to Action Scheduler
 *     $ wp pollora schedule migrate --to=action-scheduler --dry-run
 *
 *     # Move it, then back
 *     $ wp pollora schedule migrate --from=wp-cron --to=action-scheduler
 *     $ wp pollora schedule migrate --from=action-scheduler --to=wp-cron
 */
class MigrateCommand
{
    /**
     * Migrates pending events from one driver to another.
     *
     * Creates each event in the target, then takes it out of the source, so
     * running it again never duplicates an event. Hooks the driver protects
     * stay on WP-Cron.
     *
     * ## OPTIONS
     *
     * [--from=<driver>]
     * : The driver that keeps the events now. Defaults to `wp-cron`, or to the driver that still keeps events when the target is `wp-cron`.
     *
     * [--to=<driver>]
     * : The driver that takes them. Defaults to the driver of the site.
     *
     * [--dry-run]
     * : Show what would move, and move nothing.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc_args
     */
    public function __invoke($args, $assoc_args): void
    {
        $engine = Schedule::engine();
        $to = (string) ($assoc_args['to'] ?? $engine->driverName());
        $from = (string) ($assoc_args['from'] ?? $this->guessSource($to));
        $dryRun = ! empty($assoc_args['dry-run']);

        try {
            $report = (new Migrator($engine))->migrate($from, $to, $dryRun);
        } catch (\Throwable $throwable) {
            \WP_CLI::error($throwable->getMessage());
        }

        $this->print($report);
    }

    private function guessSource(string $to): string
    {
        if ($to !== CronIndex::WP_CRON) {
            return CronIndex::WP_CRON;
        }

        foreach (CronIndex::events() as $event) {
            if ($event->driver !== null && $event->driver !== CronIndex::WP_CRON) {
                return $event->driver;
            }
        }

        \WP_CLI::error('No other driver keeps events: say which one with --from.');
    }

    private function print(MigrationReport $report): void
    {
        $verb = $report->dryRun ? 'Would move' : 'Moved';

        foreach ($report->moved as $event) {
            \WP_CLI::log(sprintf('%s %s (%s)', $verb, $event->hook, $event->recurrence ?? 'once'));
        }

        foreach ($report->skipped as $skipped) {
            \WP_CLI::log(sprintf('Kept %s: %s', $skipped['event']->hook, $skipped['reason']));
        }

        foreach ($report->failed as $failed) {
            \WP_CLI::warning(sprintf('Could not move %s: %s', $failed['event']->hook, $failed['reason']));
        }

        $summary = sprintf('%s %d event(s) from %s to %s%s.', $verb, count($report->moved), $report->from, $report->to, $report->dryRun ? ' (dry run)' : '');

        $report->succeeded() ? \WP_CLI::success($summary) : \WP_CLI::error($summary.' Some events stayed where they were.');
    }
}
