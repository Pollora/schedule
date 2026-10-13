<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

/**
 * A driver that exists before it can store anything: Action Scheduler's
 * functions are there from the start, its data store only after
 * 'action_scheduler_init'. The engine keeps what is scheduled meanwhile.
 */
interface DefersUntilReady
{
    /**
     * Whether the driver can store events now.
     */
    public function ready(): bool;

    /**
     * The WordPress action that fires when the driver becomes ready.
     */
    public function readyAction(): string;
}
