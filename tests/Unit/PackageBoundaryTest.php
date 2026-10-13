<?php

declare(strict_types=1);

/**
 * pollora/schedule runs without Laravel's application: WordPress, the
 * attributes, the discovery and PSR interfaces, nothing else. The Laravel
 * driver, the Artisan commands and the service providers live in the framework.
 */
arch('schedule stays free of the Laravel application')
    ->expect('Pollora\Schedule')
    ->toOnlyUse([
        'Pollora\Schedule',
        'Pollora\Attributes',
        'Pollora\Discovery',
        'Psr\Log',
        'Psr\Container',
        'Spatie\StructureDiscoverer',
    ])
    ->ignoring([
        // The test double asserts with PHPUnit when it is there
        'PHPUnit\Framework\Assert',
        // WordPress
        'ActionScheduler',
        'ActionScheduler_Store',
        'add_action',
        'apply_filters',
        'as_get_scheduled_actions',
        'as_next_scheduled_action',
        'as_schedule_recurring_action',
        'as_schedule_single_action',
        'did_action',
        'remove_filter',
        'wp_get_schedules',
        'wp_unschedule_hook',
        'WP_Error',
        'add_filter',
        'do_action_ref_array',
        'function_exists',
        'wp_clear_scheduled_hook',
        'wp_next_scheduled',
        'wp_schedule_event',
        'wp_schedule_single_event',
        'wp_timezone',
        'wp_unschedule_hook',
        '_get_cron_array',
        '_set_cron_array',
    ]);

arch('the contract and the vocabulary do not depend on a driver')
    ->expect('Pollora\Schedule\Contracts')
    ->toOnlyUse(['Pollora\Schedule\Event', 'Pollora\Schedule\Exceptions']);
