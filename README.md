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

A driver is where events are kept and who runs them. `WpCronDriver` is the default: a thin layer over `wp_schedule_event()`, `wp_schedule_single_event()` and the other functions of WordPress, so every filter the site hangs on them keeps working. Another driver implements `Pollora\Schedule\Contracts\ScheduleDriver` and is given to the engine with `Schedule::useDriver()`.

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
