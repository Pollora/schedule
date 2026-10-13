<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

/**
 * A driver that keeps nothing it can be asked about: a queue of deferred jobs.
 *
 * The 'cron' index is its only record of what is scheduled. The engine gives
 * each placement an identifier, writes it in the entry (the 'id' key) and hands
 * it to the driver with the event; when the job runs, it runs only if the entry
 * still exists and carries its identifier. Cancelling is then removing the entry.
 */
interface Identifies
{
    /**
     * Place the event, whose identifier the engine has set; recurring or single.
     *
     * @return bool False when the driver ignored it
     *
     * @throws ScheduleException When the event cannot be scheduled
     */
    public function placeIdentified(Event $event): bool;
}
