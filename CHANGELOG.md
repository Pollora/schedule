# Changelog

All notable changes to `pollora/schedule` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ActionSchedulerDriver`: events are Action Scheduler actions, in a group of their own (`pollora/schedule`), so cancelling a hook never reaches the actions another plugin keeps on it. The core's ten-minute window ignores a duplicate single event
- `Schedule::driver('action-scheduler')`, or `POLLORA_SCHEDULE_DRIVER`, chooses the driver of the site; `Schedule::extend()` adds one. WordPress's own scheduling functions follow it, for the core, plugins and themes, through the `pre_schedule_event`, `pre_reschedule_event`, `pre_unschedule_event`, `pre_clear_scheduled_hook`, `pre_unschedule_hook` and `pre_get_scheduled_event` filters
- What stays on WP-Cron: events already in the `cron` option (nothing moves until a migration says so, so a recurring event WP-Cron runs keeps rescheduling itself there), Action Scheduler's own queue runner (`action_scheduler_run_queue`) and the hooks given to `ScheduleEngine::protect()` or the `pollora/schedule/protected_hooks` filter, and every event when the driver is not there
- Events scheduled before Action Scheduler is ready are kept and given to it on `action_scheduler_init`, once each

## [0.1.0] - 2026-10-13

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- `Schedule`, a fluent API over one engine: `Schedule::event()` for a recurring event, `Schedule::once()` for a single one, `cancel()`, `clear()`, `next()` and `events()`
- `ScheduleDriver`, the contract of a place where events are kept (WordPress's vocabulary: hook, timestamp, arguments, recurrence), and `WpCronDriver`, the default, a thin layer over WordPress's own functions that never writes the `cron` option
- `Schedule::fake()` and its assertions (`assertScheduled()`, `assertNotScheduled()`, `assertScheduledTimes()`, `assertNothingScheduled()`)
- `Recurrence` and `When`: one place for the schedule keys and the first run of an event, in the site's timezone

### Changed

- `ScheduleDiscovery` (`#[Schedule]`), moved from `pollora/framework` with its history and its FQCN, schedules through the engine: same hook names, same schedule keys
