<?php

declare(strict_types=1);

/**
 * What pollora/schedule uses of Action Scheduler, for static analysis only.
 */
class ActionScheduler
{
    public static function is_initialized(?string $function_name = null): bool {}

    public static function store(): ActionScheduler_Store {}
}

abstract class ActionScheduler_Store
{
    public const STATUS_PENDING = 'pending';

    abstract public function cancel_action($action_id);
}

/**
 * @param  array<int|string, mixed>  $args
 */
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {}

/**
 * @param  array<int|string, mixed>  $args
 */
function as_schedule_recurring_action(int $timestamp, int $interval_in_seconds, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {}

/**
 * @param  array<int|string, mixed>|null  $args
 * @return int|bool
 */
function as_next_scheduled_action(string $hook, ?array $args = null, string $group = '') {}

/**
 * @param  array<string, mixed>  $args
 * @return array<int, object>
 */
function as_get_scheduled_actions(array $args = [], string $return_format = 'OBJECT'): array {}
