<?php

declare(strict_types=1);

namespace Pollora\Schedule\Infrastructure\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Pollora\Attributes\Schedule;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Schedule\Every;
use Pollora\Schedule\Interval;
use ReflectionClass;
use ReflectionMethod;
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
 *
 * @package Pollora\Schedule\Infrastructure\Services
 */
final class ScheduleDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    /**
     * Default WordPress recurrence schedules.
     *
     * @var array<string>
     */
    private const DEFAULT_SCHEDULES = [
        'hourly',
        'twicedaily',
        'daily',
        'weekly',
    ];

    /**
     * {@inheritDoc}
     *
     * Discovers methods with Schedule attributes and collects them for registration.
     * Only processes public methods that have the Schedule attribute.
     */
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure): void
    {
        // Only process classes
        if (!$structure instanceof \Spatie\StructureDiscoverer\Data\DiscoveredClass) {
            return;
        }

        // Skip abstract classes
        if ($structure->isAbstract) {
            return;
        }

        try {
            // Use reflection to examine methods for Schedule attributes
            $reflectionClass = new ReflectionClass($structure->namespace.'\\'.$structure->name);

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $scheduleAttributes = $method->getAttributes(Schedule::class);

                if (empty($scheduleAttributes)) {
                    continue;
                }

                foreach ($scheduleAttributes as $scheduleAttribute) {
                    // Collect the method for registration
                    $this->getItems()->add($location, [
                        'class' => $structure->namespace.'\\'.$structure->name,
                        'method' => $method->getName(),
                        'attribute' => $scheduleAttribute,
                        'reflection_method' => $method,
                    ]);
                }
            }
        } catch (\Throwable $e) {
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
                $scheduleIdentifier = $this->processRecurrence($schedule->recurrence, $hookName);

                // Register the action handler that will execute the scheduled method
                add_action($hookName, function () use ($className, $methodName, $schedule) {
                    $instance = app($className);
                    
                    // Call method with arguments if provided
                    if (!empty($schedule->args)) {
                        $instance->{$methodName}($schedule->args);
                    } else {
                        $instance->{$methodName}();
                    }
                });

                // Schedule the cron event if not already scheduled
                if (!wp_next_scheduled($hookName, $schedule->args)) {
                    $this->scheduleWordPressCron($hookName, $scheduleIdentifier, $schedule->args);
                }
            } catch (\Throwable $e) {
                // Log the error but continue with other scheduled tasks
                error_log("Failed to register Schedule from method {$className}::{$methodName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Generate a hook name for a class method.
     *
     * Creates a human-readable hook name based on the class and method names
     * using snake_case formatting, following WordPress naming conventions.
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name
     *
     * @return string The generated hook name in snake_case format
     */
    private function generateHookName(string $className, string $methodName): string
    {
        // Extract just the class name without namespace
        $shortClassName = class_basename($className);
        
        // Convert to snake_case following WordPress conventions
        return Str::snake($shortClassName) . '_' . Str::snake($methodName);
    }

    /**
     * Process and validate a recurrence definition.
     *
     * Converts various recurrence types (string, array, Every, Interval) into
     * a standardized schedule identifier that can be used by WordPress cron.
     * Also handles registration of custom schedules when needed.
     *
     * @param string|array<string,mixed>|Every|Interval $recurrence The recurrence definition to process
     * @param string $hookName The hook name for custom schedule registration
     *
     * @return string The processed schedule identifier
     *
     * @throws InvalidArgumentException If the recurrence type is unsupported or invalid
     */
    private function processRecurrence(string|array|Every|Interval $recurrence, string $hookName): string
    {
        return match (true) {
            // Handle string-based WordPress schedules
            is_string($recurrence) => $this->validateAndReturnStringRecurrence($recurrence),
            
            // Handle array-based custom schedules
            is_array($recurrence) => $this->processArrayRecurrence($recurrence, $hookName),
            
            // Handle Every enum values
            $recurrence instanceof Every => $this->processEveryRecurrence($recurrence, $hookName),
            
            // Handle Interval instances
            $recurrence instanceof Interval => $this->processIntervalRecurrence($recurrence, $hookName),
            
            // Unsupported recurrence type
            default => throw new InvalidArgumentException('Unsupported recurrence type provided to Schedule attribute'),
        };
    }

    /**
     * Validate and return a string-based WordPress schedule.
     *
     * Ensures the provided string matches one of WordPress's built-in schedule intervals.
     *
     * @param string $recurrence The WordPress schedule name to validate
     *
     * @return string The validated schedule name
     *
     * @throws InvalidArgumentException If the schedule name is not recognized
     */
    private function validateAndReturnStringRecurrence(string $recurrence): string
    {
        if (!in_array($recurrence, self::DEFAULT_SCHEDULES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid WordPress schedule "%s". Allowed schedules: %s',
                    $recurrence,
                    implode(', ', self::DEFAULT_SCHEDULES)
                )
            );
        }

        return $recurrence;
    }

    /**
     * Process an array-based custom schedule.
     *
     * Validates that the array contains required 'interval' and 'display' keys,
     * registers the custom schedule with WordPress, and returns the schedule identifier.
     *
     * @param array<string,mixed> $recurrence The custom schedule definition array
     * @param string $hookName The hook name to use as schedule identifier
     *
     * @return string The custom schedule identifier
     *
     * @throws InvalidArgumentException If the array structure is invalid
     */
    private function processArrayRecurrence(array $recurrence, string $hookName): string
    {
        if (!isset($recurrence['interval']) || !is_numeric($recurrence['interval'])) {
            throw new InvalidArgumentException(
                'Custom schedule array must contain a numeric "interval" key (seconds)'
            );
        }

        if (!isset($recurrence['display']) || !is_string($recurrence['display'])) {
            throw new InvalidArgumentException(
                'Custom schedule array must contain a string "display" key (human-readable name)'
            );
        }

        // Register the custom schedule with WordPress
        $scheduleKey = 'custom_' . md5($hookName);
        $this->registerCustomSchedule($scheduleKey, $recurrence);

        return $scheduleKey;
    }

    /**
     * Process Every enum recurrence values.
     *
     * Converts Every enum values to their corresponding WordPress schedule names
     * or registers custom schedules for non-standard intervals like MONTH/YEAR.
     *
     * @param Every $recurrence The Every enum value to process
     * @param string $hookName The hook name for custom schedule registration
     *
     * @return string The processed schedule identifier
     */
    private function processEveryRecurrence(Every $recurrence, string $hookName): string
    {
        // For standard WordPress schedules, return the direct mapping
        if (!$recurrence->isCustom()) {
            return $recurrence->toScheduleKey();
        }

        // For custom schedules (MONTH, YEAR), register and return identifier
        $scheduleKey = 'every_' . strtolower($recurrence->name);
        $interval = $recurrence->toInterval();
        
        $this->registerCustomSchedule($scheduleKey, [
            'interval' => $interval->toSeconds(),
            'display' => $interval->toDisplayString(),
        ]);

        return $scheduleKey;
    }

    /**
     * Process Interval instance recurrence values.
     *
     * Registers a custom schedule for the Interval and returns its identifier.
     *
     * @param Interval $recurrence The Interval instance to process
     * @param string $hookName The hook name for custom schedule registration
     *
     * @return string The custom schedule identifier
     */
    private function processIntervalRecurrence(Interval $recurrence, string $hookName): string
    {
        // Generate unique identifier based on interval
        $scheduleKey = 'interval_' . md5($hookName . '_' . $recurrence->toSeconds());
        
        $this->registerCustomSchedule($scheduleKey, [
            'interval' => $recurrence->toSeconds(),
            'display' => $recurrence->toDisplayString(),
        ]);

        return $scheduleKey;
    }

    /**
     * Register a custom schedule with WordPress.
     *
     * Adds a new schedule interval to WordPress's cron system using the cron_schedules filter.
     * This allows custom intervals beyond WordPress's built-in options.
     *
     * @param string $scheduleKey The unique key for the custom schedule
     * @param array<string,mixed> $schedule The schedule definition with 'interval' and 'display' keys
     *
     * @return void
     */
    private function registerCustomSchedule(string $scheduleKey, array $schedule): void
    {
        add_filter('cron_schedules', function (array $schedules) use ($scheduleKey, $schedule): array {
            $schedules[$scheduleKey] = [
                'interval' => (int) $schedule['interval'],
                'display' => (string) $schedule['display'],
            ];

            return $schedules;
        });
    }

    /**
     * Schedule a WordPress cron event
     *
     * @param string $hookName The hook name to schedule
     * @param string $interval The interval (e.g., 'hourly', 'daily')
     * @param array<mixed> $args Optional arguments to pass to the hook
     *
     * @return void
     */
    private function scheduleWordPressCron(string $hookName, string $interval, array $args = []): void
    {
        if (wp_schedule_event(time(), $interval, $hookName, $args) === false) {
            error_log("Failed to schedule WordPress cron event for hook: {$hookName}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier(): string
    {
        return 'schedules';
    }
}
