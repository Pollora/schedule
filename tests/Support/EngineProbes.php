<?php

declare(strict_types=1);

namespace Pollora\Schedule\Tests\Support;

use Closure;
use Pollora\Schedule\Contracts\DefersUntilReady;
use Pollora\Schedule\Contracts\FindsEvent;
use Pollora\Schedule\Contracts\ReportsChanges;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;

/**
 * A driver that keeps its events in memory and journals what it is asked, with a hook to run inside every operation.
 */
class EngineProbe implements ScheduleDriver
{
    /** @var list<string> */
    public array $log = [];

    /** @var list<Event> */
    public array $store = [];

    public bool $available = true;

    public bool $accepts = true;

    public ?Closure $during = null;

    public function available(): bool
    {
        return $this->available;
    }

    public function once(string $hook, int $timestamp, array $args = []): bool
    {
        $this->touch('once:'.$hook);
        $this->store[] = new Event($hook, $timestamp, null, $args);

        return $this->accepts;
    }

    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
    {
        $this->touch('recurring:'.$hook);
        $this->store[] = new Event($hook, $timestamp, $recurrence, $args);

        return $this->accepts;
    }

    public function cancel(string $hook, array $args = []): int
    {
        $this->touch('cancel:'.$hook);
        $before = count($this->store);
        $this->store = array_values(array_filter($this->store, static fn (Event $event): bool => ! ($event->hook === $hook && $event->args === $args)));

        return $before - count($this->store);
    }

    public function clear(string $hook): int
    {
        $this->touch('clear:'.$hook);
        $before = count($this->store);
        $this->store = array_values(array_filter($this->store, static fn (Event $event): bool => $event->hook !== $hook));

        return $before - count($this->store);
    }

    public function next(string $hook, array $args = []): ?int
    {
        foreach ($this->store as $event) {
            if ($event->hook === $hook && $event->args === $args) {
                return $event->timestamp;
            }
        }

        return null;
    }

    public function events(): array
    {
        return $this->store;
    }

    private function touch(string $entry): void
    {
        $this->log[] = $entry;

        if ($this->during instanceof Closure) {
            ($this->during)();
        }
    }
}

class FindingProbe extends EngineProbe implements FindsEvent, ReportsChanges
{
    public ?Event $found = null;

    public ?Closure $observer = null;

    public int $observed = 0;

    public function find(string $hook, array $args = []): ?Event
    {
        return $this->found;
    }

    public function observe(Closure $changed): void
    {
        $this->observed++;
        $this->observer = $changed;
    }
}

class ReadyProbe extends EngineProbe implements DefersUntilReady
{
    public bool $ready = true;

    public function ready(): bool
    {
        return $this->ready;
    }

    public function readyAction(): string
    {
        return 'probe_ready';
    }
}
