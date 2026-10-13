<?php

declare(strict_types=1);

namespace Pollora\Schedule\Contracts;

use Pollora\Schedule\Event;

/**
 * A driver that can describe one event without listing them all, which is what
 * wp_get_scheduled_event() asks for.
 */
interface FindsEvent
{
    /**
     * The next event of the hook with exactly these arguments.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function find(string $hook, array $args = []): ?Event;
}
