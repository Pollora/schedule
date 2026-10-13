<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/schedule.png" width="100%" alt="Pollora Schedule: scheduled tasks for WordPress">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/schedule"><img src="https://img.shields.io/packagist/v/pollora/schedule" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/schedule"><img src="https://img.shields.io/packagist/dt/pollora/schedule" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/schedule/actions/workflows/tests.yml"><img src="https://github.com/Pollora/schedule/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/schedule" alt="License"></a>
</p>

Scheduled tasks for WordPress, written as code you can read: a `#[Schedule]` attribute on a method, or a fluent API where the event depends on a condition or a computed value. One engine behind both, WP-Cron as the default driver.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [Scheduling](https://pollora.dev/advanced/scheduling/). It runs without Laravel too, in a WordPress plugin, a Bedrock site or any PHP project.

## Installation

```bash
composer require pollora/schedule
```

Requires PHP 8.3+. It builds on [`pollora/attributes`](https://github.com/Pollora/attributes) and [`pollora/discovery`](https://github.com/Pollora/discovery).

## Recurring events

```php
use Pollora\Schedule\Interval;
use Pollora\Schedule\Schedule;

Schedule::event('reports_compile')->daily()->at('02:00')->call([Reports::class, 'compile']);
Schedule::event('cache_warm')->every(new Interval(minutes: 15))->call(WarmCache::class);
```

`at('02:00')` is the next time the clock of the site shows it, in the site's timezone. Declare the event on every request: the handler is attached each time, the event is scheduled only when none with the same hook and arguments exists.

## Single events

```php
Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 42])->call(SendDigest::class);
Schedule::once('close_poll')->at($poll->closesAt)->call([Polls::class, 'close']);

Schedule::cancel('send_digest', ['user' => 42]);
Schedule::next('reports_compile');   // DateTimeImmutable, or null
Schedule::events();                  // everything that is scheduled, whoever scheduled it
```

`call()` takes an invokable class, `[Class::class, 'method']`, a closure, or the name of a WordPress hook to fire. The handler receives the arguments as one array, and nothing when there are none. Give the engine a PSR-11 container (`Schedule::useContainer($container)`) and the classes are built with their dependencies; without one they are built with `new`.

## Attributes

```php
use Pollora\Attributes\Schedule;
use Pollora\Schedule\Every;

final class Reports
{
    #[Schedule(Every::DAY, hook: 'reports_compile')]
    public function compile(): void {}
}
```

`ScheduleDiscovery` finds them with [`pollora/discovery`](https://github.com/Pollora/discovery) and hands them to the engine; the hook is named after the class and method when you give none.

## Drivers

A driver is where events are kept and who runs them. `WpCronDriver` is the default: a thin layer over `wp_schedule_event()`, `wp_schedule_single_event()` and the other functions of WordPress, so every filter the site hangs on them keeps working. Choose the driver of the site with `Schedule::driver('action-scheduler')` or `POLLORA_SCHEDULE_DRIVER=action-scheduler`. Events then live in Action Scheduler (its queue, its retries, its Tools › Scheduled Actions screen), in a group of their own, and WordPress's own functions follow: `wp_schedule_event()` called by the core, a plugin or a theme reaches it too. Events already in the `cron` option stay on WP-Cron, as do Action Scheduler's own queue runner, the hooks you protect (`ScheduleEngine::protect()`, `pollora/schedule/protected_hooks`) and every event when Action Scheduler is not there.

Another driver implements `Pollora\Schedule\Contracts\ScheduleDriver` and is given to the engine with `Schedule::useDriver()`.

## Work another package queues

`new ActionSchedulerDriver('my-group')` keeps its actions in a group of its own, which the `cron` index below never follows. `enqueue($hook, $timestamp, $args)` stores an action as it is, with no duplicate check, and queues an async action when the time is now: for a caller that gives each event an identifier and queues many.

## The `cron` option as an index

Whatever the driver, the `cron` option lists every scheduled event. An event another driver keeps carries a `driver` key next to `schedule` and `args`; an entry without it is WP-Cron's. WordPress reads the option without a query, so `wp_next_scheduled()`, Site Health, WP Crontrol, Query Monitor and `wp cron event list` all see the event, and WP-Cron never runs one it does not own.

A plugin that rewrites the whole array still works: removing or moving an entry reaches the driver that keeps it.

## Changing driver

Setting `Schedule::driver('action-scheduler')` (or `POLLORA_SCHEDULE_DRIVER`) sends new events to Action Scheduler; what WP-Cron holds stays there until you move it:

```bash
wp pollora schedule migrate --to=action-scheduler --dry-run
wp pollora schedule migrate --from=wp-cron --to=action-scheduler
wp pollora schedule migrate --from=action-scheduler --to=wp-cron   # and back
```

An event is created in the target before it leaves the source, so a crash duplicates it instead of losing it, and running the command again does not create it twice. A recurring event keeps the interval its entry stores. Hooks the driver protects stay on WP-Cron.

`wp cron event list` shows the type and the driver of each event, and takes `--type=once|recurring` and `--driver=<name>` like its other filters.

## Testing

```php
Schedule::fake();

$checkout->complete($order);

Schedule::assertScheduled('send_receipt', fn (Event $event) => $event->args === ['order' => 12]);
Schedule::assertNotScheduled('send_survey');
```

Nothing is scheduled and no handler is attached while faking. `Schedule::reset()` brings the real driver back.

## License

MIT. See [LICENSE](LICENSE).
