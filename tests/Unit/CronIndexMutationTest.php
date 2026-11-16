<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Event;
use Pollora\Schedule\Support\CronIndex;

const IDX_AT = 4_000_000_000;

beforeEach(function (): void {
    $this->cron = [];
    $this->writes = 0;
    $this->purged = [];
    $this->writingDuringWrite = [];

    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily'], 'text' => ['interval' => '3600', 'display' => 'Text']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;
        $this->writes++;
        $this->writingDuringWrite[] = CronIndex::writing();

        return true;
    });
    Functions\when('wp_cache_delete')->alias(function (string $key, string $group): bool {
        $this->purged[] = $group.':'.$key;

        return true;
    });
});

function idxKey(array $args = []): string
{
    return md5(serialize($args));
}

/**
 * A cron option with what a plugin may leave in it: a version, scalars where lists belong, a non-array entry.
 *
 * @return array<int|string, mixed>
 */
function idxMessy(): array
{
    return [
        'version' => 2,
        IDX_AT - 5 => 'not a list',
        IDX_AT - 4 => ['scalar_hook' => 'not entries'],
        IDX_AT - 3 => ['junk_hook' => ['k' => 'not an entry']],
        IDX_AT => [
            'digest' => [
                'junk' => 'not an entry',
                'core' => ['schedule' => false, 'args' => [], 'driver' => 'wp-cron'],
                idxKey([1]) => ['schedule' => 'daily', 'args' => [1], 'interval' => '60', 'driver' => 'queue', 'id' => 12],
            ],
        ],
    ];
}

describe('reading the owner and the id', function (): void {
    it('skips what is not an event entry', function (): void {
        $this->cron = idxMessy();
        $this->cron[IDX_AT]['digest'][idxKey([2])] = ['schedule' => false, 'args' => [2], 'driver' => 5, 'id' => 7];

        expect(strictly(fn () => CronIndex::ownerOf('digest', [1])))->toBe('queue')
            ->and(strictly(fn () => CronIndex::ownerOf('digest', [9])))->toBeNull()
            ->and(strictly(fn () => CronIndex::ownerOf('missing', [1])))->toBeNull()
            ->and(strictly(fn () => CronIndex::ownerOf('junk_hook', ['x'])))->toBeNull()
            ->and(strictly(fn () => CronIndex::ownerOf('digest', [2])))->toBe('5')
            ->and(strictly(fn () => CronIndex::idOf('digest', [1])))->toBe('12')
            ->and(strictly(fn () => CronIndex::idOf('digest', [2])))->toBe('7')
            ->and(strictly(fn () => CronIndex::idOf('digest', [9])))->toBeNull()
            ->and(strictly(fn () => CronIndex::idOf('missing', [1])))->toBeNull();

        $this->cron[IDX_AT]['digest'][idxKey([3])] = ['schedule' => false, 'args' => [3]];
        $this->cron[IDX_AT]['digest']['text'] = 'junk';

        expect(strictly(fn () => CronIndex::idOf('digest', [3])))->toBeNull()
            ->and(strictly(fn () => CronIndex::idOf('digest', ['text'])))->toBeNull();
    });

    it('lists the owners of a hook, once each, over entries that are not events', function (): void {
        $this->cron = idxMessy();
        $this->cron[IDX_AT + 1]['digest'] = 'scalar instead of entries';

        expect(strictly(fn () => CronIndex::ownersOf('digest')))->toEqualCanonicalizing(['wp-cron', 'queue'])
            ->and(strictly(fn () => CronIndex::ownersOf('digest', [1])))->toBe(['queue'])
            ->and(strictly(fn () => CronIndex::ownersOf('scalar_hook')))->toBe([]);
    });

    it('knows whether a driver other than WP-Cron keeps something, whatever else the option holds', function (): void {
        $this->cron = idxMessy();

        expect(strictly(fn () => CronIndex::hasMarked()))->toBeTrue();

        unset($this->cron[IDX_AT]['digest'][idxKey([1])]);

        expect(strictly(fn () => CronIndex::hasMarked()))->toBeFalse();
    });

    it('counts what a driver other than WP-Cron keeps, for a hook and for exact arguments', function (): void {
        CronIndex::record(new Event('digest', IDX_AT, null, [1]), 'queue');
        CronIndex::record(new Event('digest', IDX_AT, null, [2]), 'queue');
        CronIndex::record(new Event('digest', IDX_AT, null, [3]), 'wp-cron');
        CronIndex::record(new Event('other', IDX_AT, null, [1]), 'queue');

        expect(CronIndex::countMarked('digest'))->toBe(2)
            ->and(CronIndex::countMarked('digest', [1]))->toBe(1)
            ->and(CronIndex::countMarked('digest', [3]))->toBe(0)
            ->and(CronIndex::countMarked('digest', [9]))->toBe(0)
            ->and(CronIndex::countMarked('other'))->toBe(1)
            ->and(CronIndex::countMarked('absent'))->toBe(0);
    });
});

describe('listing the events of the index', function (): void {
    it('reads each entry as a typed event and goes on past what is not one', function (): void {
        $this->cron = idxMessy();
        $this->cron[IDX_AT]['123'] = [idxKey() => ['schedule' => 7, 'args' => 'one', 'interval' => '30', 'driver' => 'queue', 'id' => 9]];
        $this->cron[IDX_AT + 1]['plain'] = [idxKey() => ['schedule' => false, 'args' => []]];

        $events = strictly(fn () => CronIndex::events());

        expect($events)->toHaveCount(4)
            ->and(array_column($events, 'hook'))->toBe(['digest', 'digest', '123', 'plain']);

        [$core, $queue, $weird, $plain] = $events;

        expect($core->driver)->toBe('wp-cron')
            ->and($queue)->toEqual(new Event('digest', IDX_AT, 'daily', [1], 60, 'queue', '12'))
            ->and($weird)->toEqual(new Event('123', IDX_AT, '7', ['one'], 30, 'queue', '9'))
            ->and($plain)->toEqual(new Event('plain', IDX_AT + 1, null, [], null, 'wp-cron', null))
            ->and(array_column(strictly(fn () => CronIndex::events('queue')), 'hook'))->toBe(['digest', '123']);
    });

    it('keeps going past entries of another owner', function (): void {
        $this->cron = [IDX_AT => ['digest' => ['a' => ['schedule' => false, 'args' => []], 'b' => ['schedule' => false, 'args' => [], 'driver' => 'queue']]]];

        expect(array_column(CronIndex::events('queue'), 'driver'))->toBe(['queue']);
    });
});

describe('recording an event', function (): void {
    it('takes the interval of the schedule only when the event has none, and 0 for a schedule nobody knows', function (): void {
        CronIndex::record(new Event('a', IDX_AT, 'daily', [], 120), 'queue');
        CronIndex::record(new Event('b', IDX_AT, 'daily'), 'queue');
        CronIndex::record(new Event('c', IDX_AT, 'weird'), 'queue');
        CronIndex::record(new Event('d', IDX_AT, 'text'), 'queue');

        expect($this->cron[IDX_AT]['a'][idxKey()]['interval'])->toBe(120)
            ->and($this->cron[IDX_AT]['b'][idxKey()]['interval'])->toBe(86400)
            ->and($this->cron[IDX_AT]['c'][idxKey()]['interval'])->toBe(0)
            ->and($this->cron[IDX_AT]['d'][idxKey()]['interval'])->toBe(3600);
    });

    it('keeps the name of the schedule an entry already had at the same interval, and the new one otherwise', function (): void {
        $this->cron = [IDX_AT => ['a' => [idxKey() => ['schedule' => 'custom_x', 'args' => [], 'interval' => 300, 'driver' => 'queue']]]];
        CronIndex::record(new Event('a', IDX_AT + 1, 'five', [], 300), 'queue');

        expect($this->cron[IDX_AT + 1]['a'][idxKey()]['schedule'])->toBe('custom_x');

        CronIndex::record(new Event('a', IDX_AT + 2, 'six', [], 360), 'queue');

        expect($this->cron[IDX_AT + 2]['a'][idxKey()]['schedule'])->toBe('six');

        $this->cron = [IDX_AT => ['a' => [idxKey() => ['schedule' => false, 'args' => [], 'interval' => 300, 'driver' => 'queue']]]];
        CronIndex::record(new Event('a', IDX_AT + 1, 'five', [], 300), 'queue');

        expect($this->cron[IDX_AT + 1]['a'][idxKey()]['schedule'])->toBe('five');
    });

    it('does not give a single event the schedule of the entry it replaces', function (): void {
        $this->cron = [IDX_AT => ['a' => [idxKey() => ['schedule' => 'weird', 'args' => [], 'driver' => 'queue', 'id' => 5]]]];
        CronIndex::record(new Event('a', IDX_AT + 1), 'queue');

        expect($this->cron[IDX_AT + 1]['a'][idxKey()])->toBe(['schedule' => false, 'args' => [], 'driver' => 'queue', 'id' => '5']);
    });

    it('keeps the schedule name as a string and puts the timestamps in order', function (): void {
        $this->cron = [IDX_AT + 9 => ['z' => [idxKey() => ['schedule' => false, 'args' => []]]], IDX_AT => ['a' => [idxKey() => ['schedule' => 7, 'args' => [], 'interval' => 300, 'driver' => 'queue']]]];
        CronIndex::record(new Event('a', IDX_AT + 3, 'five', [], 300), 'queue');

        expect($this->cron[IDX_AT + 3]['a'][idxKey()]['schedule'])->toBe('7')
            ->and(array_keys($this->cron))->toBe([IDX_AT + 3, IDX_AT + 9]);
    });

    it('purges the object cache before a change, not before a read, and flags its own write', function (): void {
        CronIndex::ownerOf('a');
        CronIndex::events();
        CronIndex::hasMarked();

        expect($this->purged)->toBe([]);

        CronIndex::record(new Event('a', IDX_AT), 'queue');

        expect($this->purged)->toBe(['options:alloptions', 'options:cron'])
            ->and($this->writingDuringWrite)->toBe([true])
            ->and(CronIndex::writing())->toBeFalse();

        $this->purged = [];
        CronIndex::forget('a', [], 'queue');

        expect($this->purged)->toBe(['options:alloptions', 'options:cron']);

        $this->purged = [];
        CronIndex::record(new Event('a', IDX_AT), 'queue');
        $this->purged = [];
        CronIndex::forgetHook('a', 'queue');

        expect($this->purged)->toBe(['options:alloptions', 'options:cron']);
    });
});

describe('forgetting', function (): void {
    it('writes nothing when a hook has nothing of this driver\'s, and tidies up when it has', function (): void {
        CronIndex::record(new Event('a', IDX_AT), 'queue');
        $this->writes = 0;

        CronIndex::forgetHook('a', 'someone else');
        CronIndex::forgetHook('absent', 'queue');
        CronIndex::forget('a', ['x'], 'queue');

        expect($this->writes)->toBe(0);

        CronIndex::forgetHook('a', 'queue');

        expect($this->writes)->toBe(1)
            ->and($this->cron)->toBe([]);
    });

    it('goes through an option with what is not entries', function (): void {
        $this->cron = idxMessy();

        strictly(function (): void {
            CronIndex::forgetHook('digest', 'queue');
            CronIndex::forget('digest', [1], 'queue');
        });

        expect(CronIndex::ownerOf('digest', [1]))->toBeNull()
            ->and($this->cron[IDX_AT]['digest'])->toHaveKey('core');
    });

    it('leaves no empty hook or timestamp behind in the entries it touches', function (): void {
        $this->cron = [IDX_AT => ['a' => [idxKey() => ['schedule' => false, 'args' => [], 'driver' => 'queue']], 'b' => [idxKey() => ['schedule' => false, 'args' => []]]]];

        CronIndex::forgetHook('a', 'queue');

        expect($this->cron)->toBe([IDX_AT => ['b' => [idxKey() => ['schedule' => false, 'args' => []]]]]);
    });
});

describe('what WP-Cron may run', function (): void {
    it('leaves out what a driver keeps whatever else the option holds', function (): void {
        $past = [];
        foreach (idxMessy() as $timestamp => $hooks) {
            $past[is_int($timestamp) ? $timestamp - 3_000_000_000 : $timestamp] = $hooks;
        }
        $this->cron = $past + [IDX_AT => ['later' => [idxKey() => ['schedule' => false, 'args' => []]]]];
        $ready = strictly(fn () => CronIndex::ready());
        $due = IDX_AT - 3_000_000_000;

        expect($ready)->toBeArray()
            ->and($ready)->not->toHaveKey(IDX_AT)
            ->and($ready[$due]['digest'])->toHaveKey('core')
            ->and($ready[$due]['digest'])->not->toHaveKey(idxKey([1]));
    });
});

describe('comparing two states of the option', function (): void {
    it('reports no change as two empty lists', function (): void {
        expect(CronIndex::changes([], []))->toBe(['removed' => [], 'moved' => []]);
    });

    it('ignores what WP-Cron keeps and what is not an entry, and goes on past it', function (): void {
        $old = idxMessy();
        $new = [IDX_AT => ['other' => []]];

        $changes = strictly(fn () => CronIndex::changes($old, $new));

        expect(array_column($changes['removed'], 'hook'))->toBe(['digest'])
            ->and($changes['removed'][0])->toEqual(new Event('digest', IDX_AT, 'daily', [1], 60, 'queue', '12'));
    });

    it('reports a moved entry at its new time, typed', function (): void {
        $entry = ['schedule' => 7, 'args' => 'x', 'interval' => '30', 'driver' => 'queue', 'id' => 9];
        $old = [IDX_AT => ['123' => [idxKey() => $entry]]];
        $new = [IDX_AT + 5 => ['123' => [idxKey() => $entry]]];

        $changes = CronIndex::changes($old, $new);

        expect($changes['removed'])->toBe([])
            ->and($changes['moved'])->toEqual([new Event('123', IDX_AT + 5, '7', ['x'], 30, 'queue', '9')]);
    });

    it('tells apart entries that differ by owner, hook or arguments only, however their names run together', function (array $left, array $right): void {
        $old = [];
        foreach ([$left, $right] as $index => [$owner, $hook, $key]) {
            $old[IDX_AT + $index][$hook][$key] = ['schedule' => false, 'args' => [], 'driver' => $owner];
        }

        $changes = CronIndex::changes($old, [IDX_AT => []]);

        expect($changes['removed'])->toHaveCount(2);
    })->with([
        'owner and hook run together' => [['ab', 'c', 'k'], ['a', 'bc', 'k']],
        'hook and key run together' => [['o', 'c', 'dk'], ['o', 'cd', 'k']],
        'hook then owner' => [['yz', 'x', 'k'], ['z', 'xy', 'k']],
        'key then owner' => [['oz', 'h', 'k'], ['z', 'h', 'ko']],
        'same hook and key, other owner' => [['a', 'h', 'k'], ['b', 'h', 'k']],
        'same owner and key, other hook' => [['a', 'h1', 'k'], ['a', 'h2', 'k']],
        'same owner and hook, other key' => [['a', 'h', 'k1'], ['a', 'h', 'k2']],
        'hook and key, again' => [['o', 'h', 'kk'], ['o', 'hk', 'k']],
    ]);
});
