<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\CircuitBreaker\Internal\BreakerState;

/**
 * Single-process {@see Storage} backed by an array.
 *
 * PHP-FPM/CLI code runs one request per process with no preemptive
 * threading, so a plain sequential method body already gives `admit()` and
 * `recordOutcome()` the atomicity the interface requires — no lock is
 * needed. Not cross-process: use {@see ApcuStorage} or {@see RedisStorage} to
 * share breaker state across workers or hosts.
 *
 * @api
 *
 * @psalm-import-type Entry from BreakerState
 */
final class InMemoryStorage implements Storage
{
    /** @var array<string, Entry> */
    private array $entries = [];

    #[\Override]
    public function admit(
        string $key,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        string $attemptId,
    ): AdmissionResult {
        $previous = $this->entryFor($key, $now);
        $result = BreakerState::admit($previous, $config, $now);
        $this->entries[$key] = $result['entry'];

        return new AdmissionResult(
            admission: $result['admission'],
            transition: $this->transition(
                key: $key,
                from: $previous['state'],
                entry: $result['entry'],
                now: $now,
                reason: $result['reason'],
            ),
        );
    }

    #[\Override]
    public function recordOutcome(
        string $key,
        Outcome $outcome,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        Admission $admission,
        \DateTimeImmutable $admittedAt,
        string $attemptId,
    ): OutcomeResult {
        $previous = $this->entryFor($key, $now);
        $result = BreakerState::recordOutcome(
            $previous,
            $outcome,
            $config,
            $now,
            $admission,
            $admittedAt,
        );
        $this->entries[$key] = $result['entry'];

        return new OutcomeResult(
            state: BreakerState::toStateRecord($result['entry']),
            transition: $this->transition(
                key: $key,
                from: $previous['state'],
                entry: $result['entry'],
                now: $now,
                reason: $result['reason'],
            ),
        );
    }

    #[\Override]
    public function snapshot(string $key): StateRecord
    {
        return BreakerState::toStateRecord($this->entries[$key] ?? BreakerState::fresh(new \DateTimeImmutable()));
    }

    #[\Override]
    public function forceState(string $key, CircuitState $state, \DateTimeImmutable $now): ?CircuitTransition
    {
        $previous = $this->entryFor($key, $now);
        $entry = BreakerState::fresh($now);
        $entry['state'] = $state;
        $this->entries[$key] = $entry;

        return $this->transition(
            key: $key,
            from: $previous['state'],
            entry: $entry,
            now: $now,
            reason: $this->forcedReason($state),
        );
    }

    /**
     * @return Entry
     */
    private function entryFor(string $key, \DateTimeImmutable $now): array
    {
        return $this->entries[$key] ?? BreakerState::fresh($now);
    }

    /**
     * @param non-empty-string $key
     * @param Entry $entry
     */
    private function transition(
        string $key,
        CircuitState $from,
        array $entry,
        \DateTimeImmutable $now,
        ?TransitionReason $reason,
    ): ?CircuitTransition {
        if ($reason === null || $from === $entry['state']) {
            return null;
        }

        return new CircuitTransition(
            breakerName: $key,
            from: $from,
            to: $entry['state'],
            occurredAt: $now,
            reason: $reason,
            state: BreakerState::toStateRecord($entry),
        );
    }

    private function forcedReason(CircuitState $state): TransitionReason
    {
        return match ($state) {
            CircuitState::Open => TransitionReason::ForcedOpen,
            CircuitState::Closed => TransitionReason::ForcedClosed,
            CircuitState::HalfOpen => TransitionReason::ForcedHalfOpen,
        };
    }
}
