<?php

declare(strict_types=1);

namespace Pollora\Schedule\Infrastructure\Services;

use Pollora\Attributes\Schedule;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Services\HasInstancePool;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\Recurrence;
use Pollora\Schedule\Schedule as ScheduleApi;
use Pollora\Schedule\ScheduleEngine;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Schedule Discovery Service
 *
 * Discovers methods decorated with Schedule attributes and registers them as scheduled tasks.
 * This discovery class scans for methods that have the #[Schedule] attribute and processes
 * them using WordPress cron functions with support for various recurrence types including
 * WordPress built-ins, Every enum values, Interval instances, and custom array definitions.
 *
 * Handles all the complex logic for processing different recurrence types, validating
 * schedule definitions, generating hook names, and registering custom WordPress schedules.
 */
final class ScheduleDiscovery implements DiscoveryInterface
{
    use HasInstancePool;
    use IsDiscovery;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ScheduleEngine $engine = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Discovers methods with Schedule attributes and collects them for registration.
     * Only processes public methods that have the Schedule attribute.
     */
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        // Only process classes
        if (! $structure instanceof DiscoveredClass) {
            return;
        }

        // Skip abstract classes
        if ($structure->isAbstract) {
            return;
        }

        try {
            $className = $structure->namespace.'\\'.$structure->name;
            $methods = $reflectionCache->getPublicMethods($className);

            foreach ($methods as $method) {
                $scheduleAttributes = $method->getAttributes(Schedule::class);

                if (empty($scheduleAttributes)) {
                    continue;
                }

                foreach ($scheduleAttributes as $scheduleAttribute) {
                    // Collect the method for registration
                    $this->getItems()->add($location, [
                        'class' => $className,
                        'method' => $method->getName(),
                        'attribute' => $scheduleAttribute,
                        'reflection_method' => $method,
                    ]);
                }
            }
        } catch (\Throwable) {
            // Skip classes that can't be reflected
            // This might happen for classes with missing dependencies
            return;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Applies discovered Schedule methods by registering them as WordPress cron events.
     * Each discovered method is processed to extract schedule information, validate
     * recurrence definitions, register custom schedules if needed, and finally
     * register the scheduled task with WordPress cron system.
     */
    public function apply(): void
    {
        foreach ($this->getItems() as $discoveredItem) {
            [
                'class' => $className,
                'method' => $methodName,
                'attribute' => $scheduleAttribute,
                'reflection_method' => $reflectionMethod
            ] = $discoveredItem;

            try {
                // Get the Schedule attribute instance
                /** @var Schedule $schedule */
                $schedule = $scheduleAttribute->newInstance();

                // Determine hook name (custom or generated)
                $hookName = $schedule->hook ?? $this->generateHookName($className, $methodName);

                // Process the recurrence definition and get the schedule identifier
                $scheduleIdentifier = Recurrence::key($schedule->recurrence, $hookName);

                // Register the action handler that will execute the scheduled method
                add_action($hookName, function () use ($className, $methodName, $schedule): void {
                    // Use instance pool if available, otherwise fallback to container
                    $instance = $this->getInstanceFromPool($className);

                    // Call method with arguments if provided
                    if ($schedule->args !== []) {
                        $instance->{$methodName}($schedule->args);
                    } else {
                        $instance->{$methodName}();
                    }
                });

                // The engine schedules the event unless it already is
                $this->scheduleWordPressCron($hookName, $scheduleIdentifier, $schedule->args);
            } catch (\Throwable $e) {
                // Log the error but continue with other scheduled tasks
                $this->logger?->error(sprintf('Failed to register Schedule from method %s::%s', $className, $methodName), ['exception' => $e]);
            }
        }
    }

    /**
     * Generate a hook name for a class method.
     *
     * Creates a human-readable hook name based on the class and method names
     * using snake_case formatting, following WordPress naming conventions.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string  $methodName  The method name
     * @return string The generated hook name in snake_case format
     */
    private function generateHookName(string $className, string $methodName): string
    {
        // Extract just the class name without namespace
        $shortClassName = basename(str_replace('\\', '/', $className));

        // Convert to snake_case following WordPress conventions
        return $this->snake($shortClassName).'_'.$this->snake($methodName);
    }

    /**
     * The value in snake case, as Laravel's Str::snake() writes it: hooks
     * already scheduled under a derived name keep that name.
     */
    private function snake(string $value): string
    {
        if (ctype_lower($value)) {
            return $value;
        }

        $value = (string) preg_replace('/\s+/u', '', ucwords($value));

        return mb_strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $value), 'UTF-8');
    }

    /**
     * Schedule a WordPress cron event, through the engine.
     *
     * @param  string  $hookName  The hook name to schedule
     * @param  string  $interval  The schedule key (e.g., 'hourly', 'daily')
     * @param  array<mixed>  $args  Optional arguments to pass to the hook
     */
    private function scheduleWordPressCron(string $hookName, string $interval, array $args = []): void
    {
        try {
            $this->engine()->schedule(new Event($hookName, $this->engine()->now(), $interval, $args));
        } catch (ScheduleException) {
            $this->logger?->error('Failed to schedule WordPress cron event for hook: '.$hookName);
        }
    }

    private function engine(): ScheduleEngine
    {
        return $this->engine ?? ScheduleApi::engine();
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier(): string
    {
        return 'schedules';
    }
}
