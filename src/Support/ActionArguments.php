<?php

declare(strict_types=1);

namespace Pollora\Schedule\Support;

/**
 * Gives a handler the arguments an event was scheduled with, whichever driver runs it.
 *
 * Action Scheduler keeps the arguments of an action with their keys (it stores them as JSON, and finds an action by
 * the same encoding), but runs the hook with `array_values()` of them: `['order' => 7]` reaches the callback as `[7]`.
 * WP-Cron keeps the keys. Right before it runs an action of the engine's group, Action Scheduler fires
 * `action_scheduler_before_execute`; the driver tells this class which action is running, and the handlers the engine
 * attaches take their keys back from it.
 *
 * Only a call that is what Action Scheduler is running right now is changed: same hook, same number of arguments,
 * same values in the same order. Anything else (the hook fired by hand, another plugin's action on the same hook)
 * is left as it came.
 */
final class ActionArguments
{
    /**
     * @var array{hook: string, args: array<int|string, mixed>}|null
     */
    private static ?array $running = null;

    /**
     * An action of the engine's group is about to run.
     *
     * @param  array<int|string, mixed>  $args  The arguments as the store keeps them
     */
    public static function running(string $hook, array $args): void
    {
        self::$running = ['hook' => $hook, 'args' => $args];
    }

    /**
     * The action that was running is done, failed or was skipped.
     */
    public static function finished(): void
    {
        self::$running = null;
    }

    /**
     * What Action Scheduler passed $hook, with the keys of the running action put back.
     *
     * @param  array<int|string, mixed>  $received
     * @return array<int|string, mixed>
     */
    public static function restore(string $hook, array $received): array
    {
        $running = self::$running;

        if ($running === null || $running['hook'] !== $hook || array_is_list($running['args']) || array_values($running['args']) !== $received) {
            return $received;
        }

        return $running['args'];
    }
}
