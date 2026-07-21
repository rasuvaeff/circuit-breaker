<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\CircuitBreaker\Internal\BreakerState;

/**
 * Single-host cross-process {@see Storage} backed by APCu.
 *
 * The whole entry for `$key` is kept as one PHP value under a single APCu
 * key. `admit()`/`recordOutcome()` fetch it, run the same pure transition
 * functions {@see InMemoryStorage} uses ({@see BreakerState}), and store the
 * result back — all under a short `apcu_add`-based lock, so the
 * read-modify-write stays atomic across workers. The lock carries a TTL, so a
 * worker that dies while holding it does not deadlock the others.
 *
 * That TTL makes the lock a LEASE, not the unconditional mutex
 * {@see RedisStorage} gets from Lua: a critical section slower than
 * `$lockTtlSeconds` can run while another worker already holds a fresh lock.
 * The commit therefore verifies ownership immediately before writing and
 * throws rather than clobbering that worker. APCu offers no way to make the
 * check and the write one step, so this narrows the window instead of closing
 * it — widen `$lockTtlSeconds` if workers can stall for longer.
 *
 * Not cross-host: APCu's shared memory segment is local to one machine. Use
 * {@see RedisStorage} to share breaker state across multiple hosts.
 *
     * If the lock spin budget is exhausted, the operation throws instead of
     * silently losing a state transition. {@see CircuitBreaker} exposes this as
     * {@see StorageFailure}, allowing the application to choose its own outage
     * policy explicitly.
 *
 * @api
 *
 * @psalm-import-type Entry from BreakerState
 */
final readonly class ApcuStorage implements Storage
{
    /**
     * @param int $lockMaxAttempts spin budget for the internal lock: acquire
     *                             spins up to $lockMaxAttempts × $lockRetryMicros µs
     *                             (default ~100ms) before giving up and treating the
     *                             operation as if the entry were untouched
     * @param int $lockRetryMicros pause between lock attempts, microseconds
     * @param int $lockTtlSeconds  lease length of the internal lock. A worker
     *                             that dies while holding it blocks the others
     *                             for at most this long; a worker whose own
     *                             critical section outlives the lease fails
     *                             with a {@see StorageFailure} instead of
     *                             committing over another worker's write
     */
    public function __construct(
        private string $keyPrefix = 'circuit-breaker:',
        private int $lockMaxAttempts = 100,
        private int $lockRetryMicros = 1_000,
        private int $lockTtlSeconds = 1,
    ) {
        if ($lockMaxAttempts < 1) {
            throw new \InvalidArgumentException('Lock max attempts must be greater than or equal to 1');
        }
        if ($lockRetryMicros < 1) {
            throw new \InvalidArgumentException('Lock retry micros must be greater than or equal to 1');
        }
        if ($lockTtlSeconds < 1) {
            throw new \InvalidArgumentException('Lock TTL seconds must be greater than or equal to 1');
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    #[\Override]
    public function admit(
        string $key,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        string $attemptId,
    ): AdmissionResult {
        $lockToken = $this->lock($key);

        if ($lockToken === null) {
            throw $this->lockFailure($key);
        }

        try {
            $previous = $this->entryFor($key, $now);
            $result = BreakerState::admit($previous, $config, $now);
            $this->commit($key, $lockToken, $result['entry']);

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
        } finally {
            $this->unlock($key, $lockToken);
        }
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
        $lockToken = $this->lock($key);

        if ($lockToken === null) {
            throw $this->lockFailure($key);
        }

        try {
            $previous = $this->entryFor($key, $now);
            $result = BreakerState::recordOutcome(
                $previous,
                $outcome,
                $config,
                $now,
                $admission,
                $admittedAt,
            );
            $this->commit($key, $lockToken, $result['entry']);

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
        } finally {
            $this->unlock($key, $lockToken);
        }
    }

    #[\Override]
    public function snapshot(string $key): StateRecord
    {
        return BreakerState::toStateRecord($this->entryFor($key, new \DateTimeImmutable()));
    }

    #[\Override]
    public function forceState(string $key, CircuitState $state, \DateTimeImmutable $now): ?CircuitTransition
    {
        $lockToken = $this->lock($key);

        if ($lockToken === null) {
            throw $this->lockFailure($key);
        }

        try {
            $previous = $this->entryFor($key, $now);
            $entry = BreakerState::fresh($now);
            $entry['state'] = $state;
            $this->commit($key, $lockToken, $entry);

            return $this->transition(
                key: $key,
                from: $previous['state'],
                entry: $entry,
                now: $now,
                reason: $this->forcedReason($state),
            );
        } finally {
            $this->unlock($key, $lockToken);
        }
    }

    /**
     * @return Entry
     */
    private function entryFor(string $key, \DateTimeImmutable $now): array
    {
        /** @var mixed $stored */
        $stored = apcu_fetch($this->entryKey($key));

        /** @var Entry|false $stored */
        return is_array($stored) ? $stored : BreakerState::fresh($now);
    }

    /**
     * Persist the new entry, but only while this worker still provably owns
     * the lock. A lease that expired mid-critical-section means another worker
     * may already have read the pre-transition entry, so committing here would
     * silently drop its write: fail loudly instead, which
     * {@see CircuitBreaker} surfaces as {@see StorageFailure}.
     *
     * This narrows the window rather than closing it — APCu cannot make the
     * ownership check and the entry write one atomic step. Widening
     * `$lockTtlSeconds` is what removes the race in practice.
     *
     * @param Entry $entry
     */
    private function commit(string $key, int $token, array $entry): void
    {
        /** @var mixed $held */
        $held = apcu_fetch($this->lockKey($key));

        if ($held !== $token) {
            throw new \RuntimeException(sprintf('APCu lock lease for breaker "%s" expired before commit', $key));
        }

        if (apcu_store($this->entryKey($key), $entry) !== true) {
            throw new \RuntimeException(sprintf('Unable to store APCu entry for breaker "%s"', $key));
        }
    }

    private function lock(string $key): ?int
    {
        $lockKey = $this->lockKey($key);
        $token = random_int(1, PHP_INT_MAX);

        for ($attempt = 0; $attempt < $this->lockMaxAttempts; ++$attempt) {
            if (apcu_add($lockKey, $token, $this->lockTtlSeconds) === true) {
                return $token;
            }

            if (apcu_cas($lockKey, 0, $token)) {
                apcu_store($lockKey, $token, $this->lockTtlSeconds);

                return $token;
            }

            usleep($this->lockRetryMicros);
        }

        return null;
    }

    private function unlock(string $key, int $token): void
    {
        apcu_cas($this->lockKey($key), $token, 0);
    }

    private function entryKey(string $key): string
    {
        return $this->keyPrefix . 'entry:' . $key;
    }

    private function lockKey(string $key): string
    {
        return $this->keyPrefix . 'lock:' . $key;
    }

    private function lockFailure(string $key): \RuntimeException
    {
        return new \RuntimeException(sprintf('Unable to acquire APCu lock for breaker "%s"', $key));
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
