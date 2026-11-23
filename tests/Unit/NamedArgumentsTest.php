<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\ActionArguments;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

/**
 * Action Scheduler stores the arguments with their keys and runs the hook with their values only
 * (ActionScheduler_Action::execute() calls do_action_ref_array() with array_values()); WP-Cron keeps the keys.
 * The handler of an event must receive the same arguments from both.
 */
beforeEach(function (): void {
    $this->actions = [];
    $this->received = [];

    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->actions[$hook][] = $callback;

        return true;
    });
    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('_set_cron_array')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn([]);
    Functions\when('wp_schedule_single_event')->justReturn(true);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    Functions\when('wp_unschedule_hook')->justReturn(0);

    // What Action Scheduler's queue runner does around an action of the store
    $this->runLikeActionScheduler = function (int $id): void {
        $action = ActionScheduler_FakeStore::$actions[$id];
        $fire = function (string $hook, mixed ...$args): void {
            foreach ($this->actions[$hook] ?? [] as $callback) {
                $callback(...$args);
            }
        };

        $fire('action_scheduler_before_execute', $id, 'WP Cron');
        $fire($action->hook, ...array_values($action->args));
        $fire('action_scheduler_after_execute', $id, $action, 'WP Cron');
    };

    $this->handler = function (array $args = []): void {
        $this->received[] = $args;
    };
});

it('gives the handler the named arguments under Action Scheduler', function (): void {
    $engine = new ScheduleEngine;
    $engine->selectDriver(new ActionSchedulerDriver);
    $engine->attach('process_order', $this->handler);
    $engine->schedule(new Event('process_order', time() + 60, null, ['order' => 7, 'notify' => true]));

    ($this->runLikeActionScheduler)(1);

    expect($this->received)->toBe([['order' => 7, 'notify' => true]]);
});

it('gives the handler the named arguments under WP-Cron, the same as Action Scheduler does', function (): void {
    $engine = new ScheduleEngine;
    $engine->attach('process_order', $this->handler);

    // wp-cron.php runs the hook with the keys: PHP turns them into named arguments of the closure's variadic
    foreach ($this->actions['process_order'] as $callback) {
        $callback(...['order' => 7, 'notify' => true]);
    }

    expect($this->received)->toBe([['order' => 7, 'notify' => true]]);
});

it('keeps a positional list as it is under Action Scheduler', function (): void {
    $engine = new ScheduleEngine;
    $engine->selectDriver(new ActionSchedulerDriver);
    $engine->attach('process_order', $this->handler);
    $engine->schedule(new Event('process_order', time() + 60, null, [7, 'x']));

    ($this->runLikeActionScheduler)(1);

    expect($this->received)->toBe([[7, 'x']]);
});

it('finds, tells and cancels an event by its named arguments under Action Scheduler', function (): void {
    $engine = new ScheduleEngine;
    $engine->selectDriver(new ActionSchedulerDriver);
    $at = time() + 3600;
    $named = ['order' => 7];

    expect($engine->place(new Event('process_order', $at, null, $named)))->toBeTrue()
        // the same event again is a duplicate, not a second action
        ->and($engine->place(new Event('process_order', $at, null, $named)))->toBeFalse()
        ->and($engine->next('process_order', $named))->toBe($at)
        // the values alone are another event
        ->and($engine->next('process_order', [7]))->toBeNull()
        ->and($engine->cancel('process_order', [7]))->toBe(0)
        ->and($engine->cancel('process_order', $named))->toBe(1)
        ->and($engine->next('process_order', $named))->toBeNull();
});

describe('the running action', function (): void {
    it('leaves a hook fired by hand alone, once the action is done', function (): void {
        ActionArguments::running('process_order', ['order' => 7]);
        ActionArguments::finished();

        expect(ActionArguments::restore('process_order', [7]))->toBe([7]);
    });

    it('leaves the arguments of another hook alone', function (): void {
        ActionArguments::running('process_order', ['order' => 7]);

        expect(ActionArguments::restore('send_digest', [7]))->toBe([7]);

        ActionArguments::finished();
    });

    it('leaves other values alone: another plugin\'s action on the same hook', function (): void {
        ActionArguments::running('process_order', ['order' => 7]);

        expect(ActionArguments::restore('process_order', [8]))->toBe([8])
            ->and(ActionArguments::restore('process_order', [7, 'more']))->toBe([7, 'more']);

        ActionArguments::finished();
    });

    it('is cleared when the action fails or is ignored', function (): void {
        $engine = new ScheduleEngine;
        $engine->selectDriver(new ActionSchedulerDriver);
        $engine->schedule(new Event('process_order', time() + 60, null, ['order' => 7]));

        foreach (['action_scheduler_failed_execution', 'action_scheduler_execution_ignored'] as $hook) {
            foreach ($this->actions['action_scheduler_before_execute'] as $callback) {
                $callback(1, 'WP Cron');
            }

            expect(ActionArguments::restore('process_order', [7]))->toBe(['order' => 7]);

            foreach ($this->actions[$hook] as $callback) {
                $callback(1, new RuntimeException, 'WP Cron');
            }

            expect(ActionArguments::restore('process_order', [7]))->toBe([7]);
        }
    });

    it('ignores an action of another group', function (): void {
        $engine = new ScheduleEngine;
        $engine->selectDriver(new ActionSchedulerDriver);
        $id = as_schedule_single_action(time() + 60, 'process_order', ['order' => 7], 'woocommerce');

        foreach ($this->actions['action_scheduler_before_execute'] as $callback) {
            $callback($id, 'WP Cron');
        }

        expect(ActionArguments::restore('process_order', [7]))->toBe([7]);
    });
});
