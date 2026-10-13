<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use Closure;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\WpCronDriver;
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
    private ScheduleDriver $driver;

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

    public function useDriver(ScheduleDriver $driver): void
    {
        $this->driver = $driver;
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
        if ($this->driver->next($event->hook, $event->args) !== null) {
            return false;
        }

        if ($event->recurrence !== null) {
            return $this->driver->recurring($event->hook, $event->timestamp, $event->recurrence, $event->args);
        }

        return $this->driver->once($event->hook, $event->timestamp, $event->args);
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
