<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Cli\CronEventCommand;
use Pollora\Schedule\Support\CronIndex;
use WP_CLI\Formatter;

require_once __DIR__.'/../Support/WpCliFake.php';

const CRON_EVENT_AT = 4_000_000_000;

function cronEvent(string $hook, ?string $schedule, string $sig = 'sig'): stdClass
{
    return (object) [
        'hook' => $hook,
        'time' => CRON_EVENT_AT,
        'sig' => $sig,
        'schedule' => $schedule ?? false,
        'recurrence' => $schedule ?? 'Non-repeating',
        'next_run_gmt' => '2096-10-02 07:06:40',
        'next_run_relative' => 'in the future',
    ];
}

beforeEach(function (): void {
    Cron_Event_Command::$events = [];
    Cron_Event_Command::$actions = [];
    Formatter::$displayed = [];

    $this->cron = [];
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('is_wp_error')->alias(fn (mixed $value): bool => $value instanceof WP_Error);
    Functions\when('wp_list_pluck')->alias(fn (array $list, string $field): array => array_column(array_map(fn ($item) => (array) $item, $list), $field));
});

afterEach(function (): void {
    Cron_Event_Command::$events = [];
    Cron_Event_Command::$actions = [];
    Formatter::$displayed = [];
});

it('adds the type and the driver of each event to the listing, WP-Cron\'s when the entry has no marker', function (): void {
    $this->cron = [CRON_EVENT_AT => [
        'reports' => ['sig' => ['schedule' => 'daily', 'args' => [], CronIndex::MARKER => 'action-scheduler']],
        'digest' => ['sig' => ['schedule' => false, 'args' => []]],
    ]];
    Cron_Event_Command::$events = [cronEvent('reports', 'daily'), cronEvent('digest', null)];

    (new CronEventCommand)->list_([], []);

    [$items, $fields] = Formatter::$displayed[0];

    expect($fields)->toBe(['hook', 'next_run_gmt', 'next_run_relative', 'recurrence', 'type', 'driver'])
        ->and(array_map(fn ($event) => [$event->hook, $event->type, $event->driver], $items))->toBe([
            ['reports', 'recurring', 'action-scheduler'],
            ['digest', 'once', 'wp-cron'],
        ]);
});

it('filters the listing on the new columns', function (): void {
    $this->cron = [CRON_EVENT_AT => [
        'reports' => ['sig' => ['schedule' => 'daily', 'args' => [], CronIndex::MARKER => 'action-scheduler']],
        'digest' => ['sig' => ['schedule' => false, 'args' => []]],
    ]];
    Cron_Event_Command::$events = [cronEvent('reports', 'daily'), cronEvent('digest', null)];

    (new CronEventCommand)->list_([], ['driver' => 'action-scheduler']);
    (new CronEventCommand)->list_([], ['type' => 'once']);
    (new CronEventCommand)->list_([], ['hook' => 'nothing']);

    expect(array_column(Formatter::$displayed[0][0], 'hook'))->toBe(['reports'])
        ->and(array_column(Formatter::$displayed[1][0], 'hook'))->toBe(['digest'])
        ->and(Formatter::$displayed[2][0])->toBe([]);
});

it('asks for the actions of a hook only when the actions field is requested', function (): void {
    Cron_Event_Command::$events = [cronEvent('reports', 'daily')];
    Cron_Event_Command::$actions = ['reports' => ['Reports::compile']];

    (new CronEventCommand)->list_([], []);
    $withoutActions = Formatter::$displayed[0][0][0];

    Cron_Event_Command::$events = [cronEvent('reports', 'daily')];
    (new CronEventCommand)->list_([], ['fields' => 'hook,actions']);

    expect($withoutActions)->not->toHaveProperty('actions')
        ->and(Formatter::$displayed[1][0][0]->actions)->toBe(['Reports::compile']);
});

it('prints the hooks on one line for the ids format, and displays nothing else', function (): void {
    Cron_Event_Command::$events = [cronEvent('reports', 'daily'), cronEvent('digest', null, 'other')];

    ob_start();
    (new CronEventCommand)->list_([], ['format' => 'ids']);
    $output = ob_get_clean();

    expect($output)->toBe('reports digest')
        ->and(Formatter::$displayed)->toBe([]);
});

it('lists nothing when the core cannot read the events', function (): void {
    Cron_Event_Command::$events = new WP_Error('cron', 'unreadable');

    (new CronEventCommand)->list_([], []);

    expect(Formatter::$displayed[0][0])->toBe([]);
});
