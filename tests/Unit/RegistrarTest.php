<?php

declare(strict_types=1);

use Pollora\Schedule\Cli\CronEventCommand;
use Pollora\Schedule\Cli\MigrateCommand;
use Pollora\Schedule\Cli\Registrar;

require_once __DIR__.'/../Support/WpCliFake.php';

// Registrar remembers that it ran: start each test from a registrar that has not
$forget = static fn () => (new ReflectionClass(Registrar::class))->setStaticPropertyValue('registered', false);

beforeEach(function () use ($forget): void {
    WP_CLI::reset();
    $forget();
});

afterEach(function () use ($forget): void {
    WP_CLI::reset();
    $forget();
});

it('registers the enriched cron command over the bundled one, and the migration, once', function (): void {
    // WP-CLI defines this constant for the process; nothing else here reads it
    defined('WP_CLI') || define('WP_CLI', true);

    Registrar::register();
    $first = WP_CLI::$commands;
    WP_CLI::$commands = [];
    Registrar::register();

    expect($first)->toBe([
        'cron event' => CronEventCommand::class,
        'pollora schedule migrate' => MigrateCommand::class,
    ])
        ->and(WP_CLI::$commands)->toBe([]);
});
