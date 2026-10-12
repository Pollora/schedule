<?php

declare(strict_types=1);

use Pollora\Schedule\Every;
use Pollora\Schedule\Infrastructure\Services\ScheduleDiscovery;
use Pollora\Schedule\Interval;

/**
 * #[Schedule] runs on WP-Cron alone and becomes pollora/schedule. The bridge
 * to Laravel's scheduler (Scheduler, its events and jobs, the wp_events table)
 * stays in the framework.
 */
arch('the WP-Cron discovery stays free of the Laravel application')
    ->expect(ScheduleDiscovery::class)
    ->toOnlyUse([
        'Pollora\Attributes',
        'Pollora\Discovery',
        Every::class,
        Interval::class,
        'Psr\Log',
        'Spatie\StructureDiscoverer',
        // WordPress
        'add_action',
        'add_filter',
        'wp_next_scheduled',
        'wp_schedule_event',
    ]);
