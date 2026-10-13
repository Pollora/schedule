<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

use Closure;

/**
 * A driver that runs its own events, and tells when one of them was run, failed
 * or was cancelled by someone else than the engine: the 'cron' option, which
 * indexes every event, follows.
 */
interface ReportsChanges
{
    /**
     * Call $changed with the hook and the arguments of an event whose state changed.
     *
     * @param  Closure(string, array<int|string, mixed>): void  $changed
     */
    public function observe(Closure $changed): void;
}
