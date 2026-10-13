<?php

declare(strict_types=1);

namespace Pollora\Schedule;

/**
 * A scheduled event, in WordPress's vocabulary: a hook that fires at a
 * timestamp with arguments, once or at each interval of a recurrence.
 */
final readonly class Event
{
    /**
     * @param  string  $hook  Action fired when the event is due
     * @param  int  $timestamp  Next run, as a UTC Unix timestamp
     * @param  string|null  $recurrence  Key of the WordPress schedule, null for a single event
     * @param  array<int|string, mixed>  $args  Arguments the hook receives
     * @param  int|null  $interval  Seconds between two runs, null for a single event
     * @param  string|null  $driver  Name of the driver that keeps the event, known when it comes from the index
     * @param  string|null  $id  Identifier of the placement, for a driver that has no store to ask (see Contracts\Identifies)
     */
    public function __construct(
        public string $hook,
        public int $timestamp,
        public ?string $recurrence = null,
        public array $args = [],
        public ?int $interval = null,
        public ?string $driver = null,
        public ?string $id = null,
    ) {}

    public function withId(?string $id): self
    {
        return new self($this->hook, $this->timestamp, $this->recurrence, $this->args, $this->interval, $this->driver, $id);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== null;
    }
}
