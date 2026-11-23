<?php

declare(strict_types=1);

use Pollora\Schedule\Schedule;
use Pollora\Schedule\Tests\Integration\Support\RecordingJob;

/**
 * What a request that wakes WP-Cron does, in a process of its own: WordPress is loaded, the site declares its events
 * (which attaches their handlers, the core runs a hook through them), then wp-cron.php runs what is due, the way a
 * system cron or a spawned request does.
 *
 *     php tests/Integration/Support/cron-runner.php <hook> [json arguments]
 */
require __DIR__.'/../bootstrap.php';

$hook = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '[]', true);

Schedule::once($hook)->with(is_array($args) ? $args : [])->call(RecordingJob::class);

chdir(ABSPATH);
require ABSPATH.'wp-cron.php';
