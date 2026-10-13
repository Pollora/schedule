<?php

declare(strict_types=1);

namespace Pollora\Schedule\Testing;

use PHPUnit\Framework\Assert;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;

/**
 * Records what would be scheduled instead of scheduling it, for tests.
 *
 *     Schedule::fake();
 *     $checkout->complete($order);
 *     Schedule::assertScheduled('send_receipt', fn (Event $event) => $event->args === ['order' => 12]);
 */
final class ScheduleFake implements ScheduleDriver
{
    /**
     * @var list<Event>
     */
    private array $events = [];

    public function available(): bool
    {
        return true;
    }

    public function once(string $hook, int $timestamp, array $args = []): bool
    {
        $this->events[] = new Event($hook, $timestamp, null, $args);

        return true;
    }

    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
    {
        $this->events[] = new Event($hook, $timestamp, $recurrence, $args);

        return true;
    }

    public function cancel(string $hook, array $args = []): int
    {
        return $this->remove(static fn (Event $event): bool => $event->hook === $hook && $event->args === $args);
    }

    public function clear(string $hook): int
    {
        return $this->remove(static fn (Event $event): bool => $event->hook === $hook);
    }

    public function next(string $hook, array $args = []): ?int
    {
        foreach ($this->scheduled($hook) as $event) {
            if ($event->args === $args) {
                return $event->timestamp;
            }
        }

        return null;
    }

    public function events(): array
    {
        return $this->scheduled();
    }

    /**
     * What was scheduled, soonest first; narrowed to a hook and by a callback when given.
     *
     * @param  (callable(Event): bool)|null  $callback
     * @return list<Event>
     */
    public function scheduled(?string $hook = null, ?callable $callback = null): array
    {
        $events = array_values(array_filter(
            $this->events,
            static fn (Event $event): bool => ($hook === null || $event->hook === $hook) && ($callback === null || $callback($event) === true),
        ));

        usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);

        return $events;
    }

    /**
     * @param  (callable(Event): bool)|null  $callback
     */
    public function assertScheduled(string $hook, ?callable $callback = null): void
    {
        self::assert(
            $this->scheduled($hook, $callback) !== [],
            sprintf('The event [%s] was not scheduled%s.', $hook, $callback === null ? '' : ' as expected'),
        );
    }

    public function assertScheduledTimes(string $hook, int $times): void
    {
        $count = count($this->scheduled($hook));

        self::assert($count === $times, sprintf('The event [%s] was scheduled %d time(s) instead of %d.', $hook, $count, $times));
    }

    /**
     * @param  (callable(Event): bool)|null  $callback
     */
    public function assertNotScheduled(string $hook, ?callable $callback = null): void
    {
        self::assert($this->scheduled($hook, $callback) === [], sprintf('The event [%s] was scheduled.', $hook));
    }

    public function assertNothingScheduled(): void
    {
        self::assert($this->events === [], sprintf('%d event(s) were scheduled.', count($this->events)));
    }

    /**
     * @param  callable(Event): bool  $matches
     */
    private function remove(callable $matches): int
    {
        $before = count($this->events);
        $this->events = array_values(array_filter($this->events, static fn (Event $event): bool => ! $matches($event)));

        return $before - count($this->events);
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        if (! $condition) {
            throw new \AssertionError($message);
        }
    }
}
