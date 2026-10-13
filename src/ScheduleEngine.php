<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use Closure;
use Pollora\Schedule\Contracts\DefersUntilReady;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Support\CronInterceptor;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The one place events are scheduled: it gives them to the driver and wires
 * the code that runs when they are due.
 *
 * Declaring an event is meant to happen on every request, so it is cheap and
 * idempotent: the handler is attached to the hook each time, the event is
 * scheduled only when none with the same hook and arguments exists.
 */
final class ScheduleEngine
{
    /**
     * Hooks that stay on WP-Cron whatever the driver: Action Scheduler's own queue runner would never run if Action Scheduler held it.
     *
     * @var list<string>
     */
    public const array PROTECTED_HOOKS = ['action_scheduler_run_queue'];

    private ScheduleDriver $driver;

    /**
     * WP-Cron, set aside when a driver other than WP-Cron is chosen: events already in the 'cron' option, protected hooks and an unavailable driver stay on it.
     */
    private ?ScheduleDriver $fallback = null;

    private ?CronInterceptor $interceptor = null;

    /**
     * @var list<string>
     */
    private array $protectedHooks = [];

    /**
     * Events scheduled before the driver could store them, by identity.
     *
     * @var array<string, Event>
     */
    private array $buffer = [];

    private bool $flushAttached = false;

    private ?ContainerInterface $container = null;

    private bool $attachesHandlers = true;

    /**
     * @var array<string, true>
     */
    private array $attached = [];

    private ?Closure $clock = null;

    public function __construct(?ScheduleDriver $driver = null)
    {
        $this->driver = $driver ?? new WpCronDriver;
    }

    public function driver(): ScheduleDriver
    {
        return $this->driver;
    }

    /**
     * Use a driver as the only place events are kept.
     */
    public function useDriver(ScheduleDriver $driver): void
    {
        $this->interceptor?->uninstall();
        $this->interceptor = null;
        $this->fallback = null;
        $this->driver = $driver;
    }

    /**
     * Use a driver for new events, WP-Cron for the rest, and have WordPress's own scheduling functions follow.
     *
     * Events already in the 'cron' option stay there, and WP-Cron keeps running
     * them: nothing moves until a migration says so. A call to wp_schedule_event()
     * by the core, a plugin or a theme reaches the driver through the 'pre_*'
     * filters of wp-includes/cron.php.
     */
    public function selectDriver(ScheduleDriver $driver): void
    {
        $this->useDriver($driver);
        $this->fallback = new WpCronDriver;
        $this->interceptor = new CronInterceptor($this);
        $this->interceptor->install();
    }

    /**
     * Hooks that stay on WP-Cron whatever the driver, with the built-in ones.
     *
     * @return list<string>
     */
    public function protectedHooks(): array
    {
        $hooks = [...self::PROTECTED_HOOKS, ...$this->protectedHooks];

        if (function_exists('apply_filters')) {
            $hooks = (array) apply_filters('pollora/schedule/protected_hooks', $hooks);
        }

        return array_values(array_unique(array_map('strval', $hooks)));
    }

    public function protect(string ...$hooks): void
    {
        $this->protectedHooks = [...$this->protectedHooks, ...$hooks];
    }

    /**
     * The driver an event goes to, or already is on.
     *
     * A new event goes to the chosen driver, unless its hook is protected or
     * the driver is not there. An event whose hook and arguments are already in
     * the 'cron' option stays on WP-Cron, so a recurring event WP-Cron runs
     * keeps rescheduling itself there.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function driverFor(string $hook, array $args = []): ScheduleDriver
    {
        if (! $this->fallback instanceof ScheduleDriver) {
            return $this->driver;
        }

        if (! $this->driver->available() || in_array($hook, $this->protectedHooks(), true) || $this->inCronOption($hook, $args)) {
            return $this->fallback;
        }

        return $this->driver;
    }

    /**
     * Whether the 'cron' option holds an event of the hook with exactly these arguments.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function inCronOption(string $hook, array $args = []): bool
    {
        $key = md5(serialize($args));

        foreach ((array) _get_cron_array() as $hooks) {
            if (is_array($hooks) && isset($hooks[$hook][$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stores that may hold an event: the chosen driver and, next to it, WP-Cron.
     *
     * @return list<ScheduleDriver>
     */
    public function stores(): array
    {
        return $this->fallback instanceof ScheduleDriver ? [$this->driver, $this->fallback] : [$this->driver];
    }

    /**
     * Build the classes handlers name with this container, so they get their dependencies.
     */
    public function useContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * Whether a handler is attached to its hook; off, an event is only given to the driver.
     */
    public function attachHandlers(bool $attach): void
    {
        $this->attachesHandlers = $attach;
    }

    /**
     * @param  (callable(): int)|null  $clock  Current Unix timestamp; the system clock by default
     */
    public function useClock(?callable $clock): void
    {
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
    }

    public function now(): int
    {
        return $this->clock instanceof Closure ? (int) ($this->clock)() : time();
    }

    /**
     * Schedule an event, unless one with the same hook and arguments is already scheduled.
     *
     * @return bool Whether the event was scheduled by this call
     *
     * @throws Exceptions\ScheduleException When the driver cannot schedule it
     */
    public function schedule(Event $event): bool
    {
        if ($this->next($event->hook, $event->args) !== null) {
            return false;
        }

        return $this->place($event);
    }

    /**
     * Give an event to the driver it belongs to, as the core's wp_schedule_event() does: no check that one exists already beyond a single event's duplicate window.
     *
     * @return bool False when an identical single event is already due
     *
     * @throws Exceptions\ScheduleException When the driver cannot schedule it
     */
    public function place(Event $event): bool
    {
        $driver = $this->driverFor($event->hook, $event->args);

        if ($driver instanceof DefersUntilReady && ! $driver->ready()) {
            return $this->buffer($driver, $event);
        }

        return $this->give($driver, $event);
    }

    /**
     * When the event with these arguments next runs, in any store, null when it is not scheduled.
     *
     * @param  array<int|string, mixed>  $args
     */
    public function next(string $hook, array $args = []): ?int
    {
        $times = [];

        foreach ($this->stores() as $store) {
            $times[] = $store->next($hook, $args);
        }

        $buffered = $this->buffer[self::identity($hook, $args)] ?? null;
        $times[] = $buffered?->timestamp;
        $times = array_filter($times, static fn (?int $time): bool => $time !== null);

        return $times === [] ? null : min($times);
    }

    /**
     * Cancel the events of a hook with these arguments, in every store.
     *
     * @param  array<int|string, mixed>  $args
     * @return int Number of events cancelled
     */
    public function cancel(string $hook, array $args = []): int
    {
        $count = 0;

        foreach ($this->stores() as $store) {
            $count += $store->cancel($hook, $args);
        }

        if (isset($this->buffer[self::identity($hook, $args)])) {
            unset($this->buffer[self::identity($hook, $args)]);
            $count++;
        }

        return $count;
    }

    /**
     * Cancel every event of a hook, in every store.
     */
    public function clear(string $hook): int
    {
        $count = 0;

        foreach ($this->stores() as $store) {
            $count += $store->clear($hook);
        }

        foreach ($this->buffer as $identity => $event) {
            if ($event->hook === $hook) {
                unset($this->buffer[$identity]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Everything that is scheduled, in every store, soonest first.
     *
     * @return list<Event>
     */
    public function events(): array
    {
        $events = array_values($this->buffer);

        foreach ($this->stores() as $store) {
            array_push($events, ...$store->events());
        }

        usort($events, static fn (Event $a, Event $b): int => $a->timestamp <=> $b->timestamp);

        return $events;
    }

    /**
     * Give the driver what was scheduled while it could not store anything.
     *
     * An event already there is not scheduled again: before the driver is ready,
     * a plugin that checks wp_next_scheduled() before it schedules sees nothing
     * and schedules on every request.
     */
    public function flush(): void
    {
        $events = $this->buffer;
        $this->buffer = [];

        foreach ($events as $event) {
            $driver = $this->driverFor($event->hook, $event->args);

            if ($driver->next($event->hook, $event->args) === null) {
                $this->give($driver, $event);
            }
        }
    }

    private function give(ScheduleDriver $driver, Event $event): bool
    {
        if ($event->recurrence !== null) {
            return $driver->recurring($event->hook, $event->timestamp, $event->recurrence, $event->args);
        }

        return $driver->once($event->hook, $event->timestamp, $event->args);
    }

    private function buffer(DefersUntilReady $driver, Event $event): bool
    {
        $this->buffer[self::identity($event->hook, $event->args)] ??= $event;

        if (! $this->flushAttached) {
            $this->flushAttached = true;
            add_action($driver->readyAction(), $this->flush(...), 20);
        }

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private static function identity(string $hook, array $args): string
    {
        return $hook.'|'.md5(serialize($args));
    }

    /**
     * Run $handler when $hook fires.
     *
     * @param  string|array{0: class-string|object, 1: string}|Closure  $handler  An invokable class, [class, method], a closure, or another hook to fire
     */
    public function attach(string $hook, string|array|Closure $handler): void
    {
        if (! $this->attachesHandlers) {
            return;
        }

        $key = $hook.'|'.self::describe($handler);

        if (isset($this->attached[$key])) {
            return;
        }

        $this->attached[$key] = true;

        add_action($hook, function (mixed ...$received) use ($hook, $handler): void {
            $this->run($hook, $handler, $received);
        }, 10, PHP_INT_MAX);
    }

    /**
     * @param  array<int|string, mixed>  $args  What WordPress passed the hook
     */
    private function run(string $hook, string|array|Closure $handler, array $args): void
    {
        if (is_string($handler) && ! class_exists($handler)) {
            do_action_ref_array($handler, $args);

            return;
        }

        $callable = $this->resolve($handler);

        if ($args === []) {
            $callable();

            return;
        }

        $callable($args);
    }

    private function resolve(string|array|Closure $handler): callable
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_string($handler)) {
            return $this->instance($handler);
        }

        [$target, $method] = $handler;

        return [is_object($target) ? $target : $this->instance($target), $method];
    }

    private function instance(string $class): object
    {
        if ($this->container instanceof ContainerInterface) {
            try {
                return $this->container->get($class);
            } catch (NotFoundExceptionInterface $exception) {
                // Not something the container knows: build it plainly, unless it needs arguments the container failed to give.
                if (((new \ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                    throw $exception;
                }
            }
        }

        return new $class;
    }

    /**
     * @param  string|array{0: class-string|object, 1: string}|Closure  $handler
     */
    private static function describe(string|array|Closure $handler): string
    {
        return match (true) {
            is_string($handler) => $handler,
            $handler instanceof Closure => 'closure:'.spl_object_id($handler),
            is_object($handler[0]) => $handler[0]::class.'@'.$handler[1].':'.spl_object_id($handler[0]),
            default => $handler[0].'@'.$handler[1],
        };
    }
}
