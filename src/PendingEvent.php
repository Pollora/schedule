<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use Closure;
use DateTimeInterface;
use LogicException;

/**
 * An event being described; call() or schedule() ends the description and
 * schedules it.
 *
 *     Schedule::event('reports_compile')->daily()->at('02:00')->call([Reports::class, 'compile']);
 *     Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 42])->call(SendDigest::class);
 */
final class PendingEvent
{
    /**
     * @var string|array<string, mixed>|Every|Interval|null
     */
    private string|array|Every|Interval|null $recurrence = null;

    private string|DateTimeInterface|int|null $moment = null;

    /**
     * @var array<int|string, mixed>
     */
    private array $args = [];

    public function __construct(
        private readonly ScheduleEngine $engine,
        private readonly string $hook,
        private readonly bool $recurring,
    ) {}

    public function hourly(): self
    {
        return $this->every(Every::HOUR);
    }

    public function twiceDaily(): self
    {
        return $this->every(Every::TWICE_DAILY);
    }

    public function daily(): self
    {
        return $this->every(Every::DAY);
    }

    public function weekly(): self
    {
        return $this->every(Every::WEEK);
    }

    public function monthly(): self
    {
        return $this->every(Every::MONTH);
    }

    public function yearly(): self
    {
        return $this->every(Every::YEAR);
    }

    /**
     * @param  string|array<string, mixed>|Every|Interval  $recurrence  A WordPress schedule name, ['interval' => seconds, 'display' => name], an Every case or an Interval
     */
    public function every(string|array|Every|Interval $recurrence): self
    {
        if (! $this->recurring) {
            throw new LogicException(sprintf("The event '%s' is a single event: it has no recurrence.", $this->hook));
        }

        $this->recurrence = $recurrence;

        return $this;
    }

    /**
     * When the event is first due: a timestamp, a date, a DateTimeInterface, or 'HH:MM' for the next time the clock shows it (site timezone).
     */
    public function at(string|DateTimeInterface|int $moment): self
    {
        $this->moment = $moment;

        return $this;
    }

    /**
     * Due after a delay.
     */
    public function in(int $seconds = 0, int $minutes = 0, int $hours = 0, int $days = 0): self
    {
        return $this->at($this->engine->now() + (new Interval(seconds: $seconds, minutes: $minutes, hours: $hours, days: $days))->totalSeconds());
    }

    /**
     * Arguments the hook receives; they identify the event, so cancel() takes the same.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function with(array $args): self
    {
        $this->args = $args;

        return $this;
    }

    /**
     * Run $handler when the event is due, and schedule the event.
     *
     * The handler gets the arguments as one array, none when there are no
     * arguments, as #[Schedule] methods do. A class is built by the container.
     *
     * @param  string|array{0: class-string|object, 1: string}|Closure  $handler  An invokable class, [class, method], a closure, or the name of a WordPress hook to fire
     */
    public function call(string|array|Closure $handler): Event
    {
        $this->engine->attach($this->hook, $handler);

        return $this->schedule();
    }

    /**
     * Schedule the event for a hook that something else handles.
     */
    public function schedule(): Event
    {
        $now = $this->engine->now();
        $timestamp = $this->moment === null ? $now : When::resolve($this->moment, $now);

        if ($this->recurring) {
            if ($this->recurrence === null) {
                throw new LogicException(sprintf("The recurring event '%s' has no recurrence: call daily(), every()…", $this->hook));
            }

            $event = new Event($this->hook, $timestamp, Recurrence::key($this->recurrence, $this->hook), $this->args);
        } else {
            $event = new Event($this->hook, $timestamp, null, $this->args);
        }

        $this->engine->schedule($event);

        return $event;
    }
}
