# Changelog

All notable changes to `pollora/schedule` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Schedule`, a fluent API over one engine: `Schedule::event()` for a recurring event, `Schedule::once()` for a single one, `cancel()`, `clear()`, `next()` and `events()`
- `ScheduleDriver`, the contract of a place where events are kept (WordPress's vocabulary: hook, timestamp, arguments, recurrence), and `WpCronDriver`, the default, a thin layer over WordPress's own functions that never writes the `cron` option
- `Schedule::fake()` and its assertions (`assertScheduled()`, `assertNotScheduled()`, `assertScheduledTimes()`, `assertNothingScheduled()`)
- `Recurrence` and `When`: one place for the schedule keys and the first run of an event, in the site's timezone

### Changed

- `ScheduleDiscovery` (`#[Schedule]`), moved from `pollora/framework` with its history and its FQCN, schedules through the engine: same hook names, same schedule keys
