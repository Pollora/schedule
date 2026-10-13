# Changelog

All notable changes to `pollora/schedule` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

What a driver with no store of its own (a queue of deferred jobs) needs. `ScheduleDriver` is unchanged.

### Added

- `Contracts\Identifies`: a driver that can only be told what to run, not asked what it holds. The engine gives each placement an identifier (`Event::$id`), hands it over with `placeIdentified()` and writes it in the `cron` entry (`id` key); the driver's job runs only if the entry still exists and carries its identifier, so cancelling is removing the entry
- `CronIndex::idOf()`, and `id` on the events `CronIndex::events()` lists. Recording an event again without an identifier keeps the one the entry had
- `ScheduleEngine::hand()`: give an event to a driver the way the engine does. Migrations and the replay of a direct write to the `cron` option go through it, so a placement made there is identified too

## [0.1.2] - 2026-10-13

What pollora/hook 2.0 queues through: Action Scheduler groups of the caller's choice, and `enqueue()`. `ScheduleDriver` is unchanged.

### Added

- `ActionSchedulerDriver` takes the group of its actions (`new ActionSchedulerDriver('crm')`), `pollora/schedule` by default. Every query, cancellation and observation uses the instance's group, and the `cron` index follows the default group only: another package can queue work in a group of its own without it ever reaching the index
- `Contracts\Enqueues` and `ActionSchedulerDriver::enqueue()`: store an event as it is, with no duplicate check, for a caller that gives each event an identifier of its own. A time that is now or past queues an async action (`as_enqueue_async_action()`)

## [0.1.1] - 2026-10-13

The Action Scheduler driver and the `cron` option as an index of every driver. `ScheduleDriver` is unchanged, so the framework's `^0.1` still holds; 1.0.0 comes with the framework's release.

### Added

- `ActionSchedulerDriver`: events are Action Scheduler actions, in a group of their own (`pollora/schedule`), so cancelling a hook never reaches the actions another plugin keeps on it. The core's ten-minute window ignores a duplicate single event
- `Schedule::driver('action-scheduler')`, or `POLLORA_SCHEDULE_DRIVER`, chooses the driver of the site; `Schedule::extend()` adds one. WordPress's own scheduling functions follow it, for the core, plugins and themes, through the `pre_schedule_event`, `pre_reschedule_event`, `pre_unschedule_event`, `pre_clear_scheduled_hook`, `pre_unschedule_hook` and `pre_get_scheduled_event` filters
- What stays on WP-Cron: events already in the `cron` option (nothing moves until a migration says so, so a recurring event WP-Cron runs keeps rescheduling itself there), Action Scheduler's own queue runner (`action_scheduler_run_queue`) and the hooks given to `ScheduleEngine::protect()` or the `pollora/schedule/protected_hooks` filter, and every event when the driver is not there
- Events scheduled before Action Scheduler is ready are kept and given to it on `action_scheduler_init`, once each
- The `cron` option indexes every event, whoever keeps it: an event another driver keeps is written there with a `driver` key (an entry without it is WP-Cron's own), so `wp_next_scheduled()`, Site Health, WP Crontrol and Query Monitor see it without a query to the driver. The engine writes the entry when it schedules and cancels, and Action Scheduler's own reports (stored, completed, failed, cancelled) keep it current
- WP-Cron never runs an event another driver keeps (`pre_get_ready_cron_jobs`), and a direct write to the option that removes or moves such an entry reaches its driver (`pre_update_option_cron`). Running an event with `wp cron event run`, which reschedules and then unschedules the old time, leaves it scheduled
- `Migrator`, `ScheduleEngine::reindex()`: move pending events from one driver to another, in both directions and safe to run again (the event is created in the target before it leaves the source), keeping a recurring event's own interval; `--dry-run` shows what would move. Hooks the driver protects stay on WP-Cron
- WP-CLI: `wp cron event list` gains `type` (`once` or `recurring`) and `driver` and their filters, extending the command WP-CLI bundles without removing anything; `wp pollora schedule migrate [--from=] [--to=] [--dry-run]`
- `Schedule::boot()`: an application that starts before WordPress chooses the driver at once, and the engine hooks into WordPress as soon as `add_filter()` exists
- `Contracts\ReportsChanges`, for a driver that runs its own events and tells when one ran, failed or was cancelled; `Event::$driver`

### Changed

- `pre_get_scheduled_event` is no longer filtered: the index answers `wp_get_scheduled_event()` natively

## [0.1.0] - 2026-10-13

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- `Schedule`, a fluent API over one engine: `Schedule::event()` for a recurring event, `Schedule::once()` for a single one, `cancel()`, `clear()`, `next()` and `events()`
- `ScheduleDriver`, the contract of a place where events are kept (WordPress's vocabulary: hook, timestamp, arguments, recurrence), and `WpCronDriver`, the default, a thin layer over WordPress's own functions that never writes the `cron` option
- `Schedule::fake()` and its assertions (`assertScheduled()`, `assertNotScheduled()`, `assertScheduledTimes()`, `assertNothingScheduled()`)
- `Recurrence` and `When`: one place for the schedule keys and the first run of an event, in the site's timezone

### Changed

- `ScheduleDiscovery` (`#[Schedule]`), moved from `pollora/framework` with its history and its FQCN, schedules through the engine: same hook names, same schedule keys
