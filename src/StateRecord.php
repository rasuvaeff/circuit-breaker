<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Snapshot of a breaker's state and counters, as returned by
 * {@see Storage::admit()}'s side effects, {@see Storage::recordOutcome()},
 * and {@see Storage::snapshot()}.
 *
 * Meaning of `successes`/`failures` depends on `state`:
 *
 * - `Closed`: live counts of the sliding ring buffer (see {@see Ratio}) —
 *   how many of the last `window` calls, within `within`, succeeded/failed.
 * - `HalfOpen`: `successes` is the count of consecutive probe successes
 *   toward `successThreshold`; `failures` is always 0 (a probe failure
 *   transitions to `Open` immediately, so a `HalfOpen` snapshot with a
 *   recorded failure is never observable).
 * - `Open`: both are frozen at whatever the `Closed` window held at the
 *   moment of the triggering transition — nothing updates them while `Open`.
 *
 * `rejected` counts calls turned away since the breaker most recently
 * transitioned `Closed` → `Open`; it resets to 0 when the breaker returns to
 * `Closed` (naturally or via `forceState()`).
 *
 * @api
 */
final readonly class StateRecord
{
    public function __construct(
        private CircuitState $state,
        private \DateTimeImmutable $openedAt,
        private int $successes,
        private int $failures,
        private int $rejected,
    ) {}

    public function state(): CircuitState
    {
        return $this->state;
    }

    public function openedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function successes(): int
    {
        return $this->successes;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function rejected(): int
    {
        return $this->rejected;
    }
}
