<?php

declare(strict_types=1);

/**
 * An in-memory Action Scheduler: the functions and classes pollora/schedule uses,
 * with the semantics that matter (groups, exact arguments, pending and running).
 */
final class FakeActionSchedule
{
    public function __construct(public ?int $timestamp, public ?int $interval = null) {}

    public function get_date(): ?DateTimeImmutable
    {
        return $this->timestamp === null ? null : new DateTimeImmutable('@'.$this->timestamp);
    }

    public function get_recurrence(): ?int
    {
        return $this->interval;
    }
}

final class FakeAction
{
    public function __construct(
        public int $id,
        public string $hook,
        public array $args,
        public string $group,
        public string $status,
        public FakeActionSchedule $schedule,
    ) {}

    public function get_hook(): string
    {
        return $this->hook;
    }

    public function get_args(): array
    {
        return $this->args;
    }

    public function get_schedule(): FakeActionSchedule
    {
        return $this->schedule;
    }
}

abstract class ActionScheduler_Store
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'in-progress';

    public const STATUS_CANCELED = 'canceled';

    abstract public function cancel_action($action_id);
}

final class ActionScheduler_FakeStore extends ActionScheduler_Store
{
    /** @var array<int, FakeAction> */
    public static array $actions = [];

    public static int $lastId = 0;

    public function cancel_action($action_id): void
    {
        self::$actions[$action_id]->status = self::STATUS_CANCELED;
    }
}

final class ActionScheduler
{
    public static bool $initialized = true;

    public static function is_initialized(?string $function_name = null): bool
    {
        return self::$initialized;
    }

    public static function store(): ActionScheduler_Store
    {
        return new ActionScheduler_FakeStore;
    }

    public static function reset(): void
    {
        ActionScheduler_FakeStore::$actions = [];
        ActionScheduler_FakeStore::$lastId = 0;
        self::$initialized = true;
    }
}

function fake_as_create(string $hook, array $args, string $group, int $timestamp, ?int $interval): int
{
    $id = ++ActionScheduler_FakeStore::$lastId;
    ActionScheduler_FakeStore::$actions[$id] = new FakeAction($id, $hook, $args, $group, 'pending', new FakeActionSchedule($timestamp, $interval));

    return $id;
}

function as_schedule_single_action($timestamp, $hook, $args = [], $group = '', $unique = false, $priority = 10): int
{
    return ActionScheduler::$initialized ? fake_as_create($hook, $args, $group, (int) $timestamp, null) : 0;
}

function as_schedule_recurring_action($timestamp, $interval, $hook, $args = [], $group = '', $unique = false, $priority = 10): int
{
    return ActionScheduler::$initialized ? fake_as_create($hook, $args, $group, (int) $timestamp, (int) $interval) : 0;
}

function as_next_scheduled_action($hook, $args = null, $group = '')
{
    $matching = as_get_scheduled_actions(['hook' => $hook, 'args' => $args, 'group' => $group, 'status' => ['pending', 'in-progress']]);

    foreach ($matching as $action) {
        if ($action->status === 'in-progress') {
            return true;
        }
    }

    foreach ($matching as $action) {
        if ($action->status === 'pending') {
            return $action->schedule->timestamp;
        }
    }

    return false;
}

function as_get_scheduled_actions($args = [], $return_format = 'OBJECT'): array
{
    $statuses = (array) ($args['status'] ?? ['pending']);
    $found = array_filter(ActionScheduler_FakeStore::$actions, static fn (FakeAction $action): bool => in_array($action->status, $statuses, true)
        && (! isset($args['hook']) || $action->hook === $args['hook'])
        && (($args['group'] ?? '') === '' || $action->group === $args['group'])
        && (! array_key_exists('args', $args) || $args['args'] === null || $action->args === $args['args']));

    uasort($found, static fn (FakeAction $a, FakeAction $b): int => $a->schedule->timestamp <=> $b->schedule->timestamp);

    return $found;
}
