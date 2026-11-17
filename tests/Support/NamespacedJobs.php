<?php

declare(strict_types=1);

namespace Pollora\Schedule\Tests\Support;

use Pollora\Attributes\Schedule;

final class NamespacedJobs
{
    /** @var list<string> */
    public static array $calls = [];

    #[Schedule('hourly')]
    public function tick(): void
    {
        self::$calls[] = 'tick';
    }
}

final class FailingJobs
{
    #[Schedule('fortnightly')]
    public function run(): void {}
}
