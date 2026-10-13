<?php

declare(strict_types=1);

namespace Pollora\Schedule\Cli;

use Pollora\Schedule\Support\CronIndex;
use stdClass;
use WP_CLI\Formatter;

/**
 * `wp cron event`, with what the 'cron' option now knows: the type of each event
 * and the driver that keeps it.
 *
 * It extends the command WP-CLI bundles and takes nothing away: the columns,
 * the filters and the formats it had still work, and `schedule`, `unschedule`
 * and `delete` are the parent's, which go through the core functions the engine
 * already follows. What changes besides the list is `run`: running an event
 * another driver keeps must not reschedule it nor unschedule it, since the
 * driver keeps its own schedule.
 */
class CronEventCommand extends \Cron_Event_Command
{
    private const array FIELDS = ['hook', 'next_run_gmt', 'next_run_relative', 'recurrence', 'type', 'driver'];

    /**
     * Lists scheduled cron events.
     *
     * ## OPTIONS
     *
     * [--fields=<fields>]
     * : Limit the output to specific object fields.
     *
     * [--<field>=<value>]
     * : Filter by one or more fields.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - ids
     *   - json
     *   - yaml
     * ---
     *
     * ## AVAILABLE FIELDS
     *
     * These fields will be displayed by default for each cron event:
     *
     * * hook
     * * next_run_gmt
     * * next_run_relative
     * * recurrence
     * * type: `once` or `recurring`
     * * driver: the driver that keeps the event (`wp-cron`, `action-scheduler`…)
     *
     * These fields are optionally available:
     *
     * * time
     * * sig
     * * args
     * * schedule
     * * interval
     * * next_run
     * * actions
     *
     * ## EXAMPLES
     *
     *     $ wp cron event list --fields=hook,type,driver,next_run_relative
     *     $ wp cron event list --type=once --driver=action-scheduler --format=json
     *
     * @subcommand list
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc_args
     */
    public function list_($args, $assoc_args): void
    {
        $formatter = new Formatter($assoc_args, self::FIELDS, 'event');
        $events = static::get_cron_events();

        if (is_wp_error($events)) {
            $events = [];
        }

        $crons = (array) _get_cron_array();

        foreach ($events as $event) {
            $event->driver = self::driverOf($crons, $event);
            $event->type = $event->schedule === false ? 'once' : 'recurring';
        }

        if (in_array('actions', (array) $formatter->fields, true)) {
            foreach ($events as $event) {
                $event->actions = static::get_hook_actions($event->hook);
            }
        }

        foreach ($events as $key => $event) {
            foreach (['hook', 'next_run_gmt', 'next_run_relative', 'recurrence', 'type', 'driver'] as $field) {
                if (! empty($assoc_args[$field]) && $event->{$field} !== $assoc_args[$field]) {
                    unset($events[$key]);
                    break;
                }
            }
        }

        if ($formatter->format === 'ids') {
            echo implode(' ', wp_list_pluck($events, 'hook'));

            return;
        }

        $formatter->display_items($events);
    }

    /**
     * An event another driver keeps runs by itself: run its hook, and leave its schedule alone.
     */
    protected static function run_event(stdClass $event)
    {
        $crons = (array) _get_cron_array();

        if (self::driverOf($crons, $event) === CronIndex::WP_CRON) {
            return parent::run_event($event);
        }

        if (! defined('DOING_CRON')) {
            define('DOING_CRON', true);
        }

        if ($event->schedule === false) {
            wp_unschedule_event($event->time, $event->hook, $event->args);
        }

        do_action_ref_array($event->hook, $event->args);

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $crons
     */
    private static function driverOf(array $crons, stdClass $event): string
    {
        return (string) ($crons[$event->time][$event->hook][$event->sig][CronIndex::MARKER] ?? CronIndex::WP_CRON);
    }
}
