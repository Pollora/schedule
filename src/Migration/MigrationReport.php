<?php

declare(strict_types=1);

namespace Pollora\Schedule\Migration;

use Pollora\Schedule\Event;

/**
 * What a migration did, or with --dry-run what it would do.
 */
final readonly class MigrationReport
{
    /**
     * @param  list<Event>  $moved  Events now kept by the target
     * @param  list<array{event: Event, reason: string}>  $skipped  Events left where they are, on purpose
     * @param  list<array{event: Event, reason: string}>  $failed  Events that could not be moved: still where they were
     */
    public function __construct(
        public string $from,
        public string $to,
        public bool $dryRun,
        public array $moved = [],
        public array $skipped = [],
        public array $failed = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->failed === [];
    }
}
