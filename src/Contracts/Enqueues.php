<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

use Pollora\Schedule\Exceptions\ScheduleException;

/**
 * A driver that can store an event as it is: no look at what is already there,
 * no duplicate window.
 *
 * For a caller that gives each event an identifier of its own, which makes a
 * duplicate impossible, and queues many of them: the check is a query for
 * nothing. The event does not go through the engine, so the 'cron' index never
 * sees it.
 */
interface Enqueues
{
    /**
     * @param  array<int|string, mixed>  $args
     * @param  int  $timestamp  When it runs; now or earlier means as soon as possible
     *
     * @throws ScheduleException When the driver does not store it
     */
    public function enqueue(string $hook, int $timestamp, array $args = []): void;
}
