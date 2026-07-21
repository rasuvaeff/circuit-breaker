<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Integration;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\ApcuStorage;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Tests\Support\ApcuStoreFailure;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\CircuitBreaker\TransitionReason;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ApcuStorage::class)]
final class ApcuIntegrationTest
{
    use StorageCalls;

    private const string NAME = 'it';

    private ?ApcuStorage $storage = null;

    #[BeforeTest]
    public function setUp(): void
    {
        if (!ApcuStorage::isAvailable()) {
            return;
        }

        apcu_clear_cache();
        $this->storage = new ApcuStorage();
    }

    public function admitAndRecordOutcomeRoundTripThroughRealApcu(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $this->admitOn($this->storage, self::NAME, $config, $now)->admission();
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $now)->state();

        Assert::same($record->state(), CircuitState::Open);

        // A fresh instance must see the same state - APCu is process-shared, not
        // instance-local.
        $fresh = new ApcuStorage();
        Assert::same($fresh->snapshot(self::NAME)->state(), CircuitState::Open);
    }

    public function keysAreIsolated(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $this->recordOn($this->storage, 'a', Outcome::Failure, $config, $now)->state();

        Assert::same($this->storage->snapshot('a')->state(), CircuitState::Open);
        Assert::same($this->storage->snapshot('b')->state(), CircuitState::Closed);
    }

    public function differentKeyPrefixesAreIsolated(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $a = new ApcuStorage('a:');
        $b = new ApcuStorage('b:');

        $this->recordOn($a, self::NAME, Outcome::Failure, $config, $now)->state();

        Assert::same($a->snapshot(self::NAME)->state(), CircuitState::Open);
        Assert::same($b->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    public function forceStateResetsAcrossInstances(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $now)->state();

        $this->storage->forceState(self::NAME, CircuitState::Closed, $now);

        $fresh = new ApcuStorage();
        Assert::same($fresh->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    /**
     * `forceState()` must release its internal lock before returning: two
     * calls in immediate succession must both take effect. If the lock were
     * leaked, the second call's spin budget (~100ms default) would be far
     * shorter than the lock's own TTL (1s), so it would silently no-op and
     * this test would observe the FIRST state instead of the second.
     */
    public function forceStateReleasesItsLockForAnImmediatelyFollowingCall(): void
    {
        if ($this->storage === null) {
            return;
        }

        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $this->storage->forceState(self::NAME, CircuitState::Open, $now);
        $this->storage->forceState(self::NAME, CircuitState::Closed, $now);

        Assert::same($this->storage->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    public function staleOwnerCannotReleaseReplacementLock(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-owner-test:';
        $storage = new ApcuStorage(keyPrefix: $prefix, lockMaxAttempts: 1, lockRetryMicros: 1);
        $lock = new \ReflectionMethod(ApcuStorage::class, 'lock');
        $unlock = new \ReflectionMethod(ApcuStorage::class, 'unlock');
        $oldToken = $lock->invoke($storage, self::NAME);
        Assert::true(is_int($oldToken));

        $replacementToken = $oldToken + 1;
        apcu_store($prefix . 'lock:' . self::NAME, $replacementToken, 1);
        $unlock->invoke($storage, self::NAME, $oldToken);

        Assert::same(apcu_fetch($prefix . 'lock:' . self::NAME), $replacementToken);

        apcu_delete($prefix . 'lock:' . self::NAME);
    }

    public function occupiedLockCannotBeAcquiredThroughFailedCas(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-cas-test:';
        $storage = new ApcuStorage(keyPrefix: $prefix, lockMaxAttempts: 1, lockRetryMicros: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $storage->forceState(self::NAME, CircuitState::Closed, $now);
        apcu_store($prefix . 'lock:' . self::NAME, 42, 10);

        $caught = null;

        try {
            $storage->forceState(self::NAME, CircuitState::Open, $now);
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            apcu_delete($prefix . 'lock:' . self::NAME);
        }

        Assert::instanceOf($caught, \RuntimeException::class);
        Assert::string($caught->getMessage())->contains('Unable to acquire APCu lock');
        Assert::same($storage->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    public function recordOutcomeThrowsWhenLockCannotBeAcquired(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-record-lock-test:';
        $storage = new ApcuStorage(keyPrefix: $prefix, lockMaxAttempts: 1, lockRetryMicros: 1);
        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        apcu_store($prefix . 'lock:' . self::NAME, 42, 10);
        $caught = null;

        try {
            $this->recordOn($storage, self::NAME, Outcome::Failure, $config, $now)->state();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            apcu_delete($prefix . 'lock:' . self::NAME);
        }

        Assert::instanceOf($caught, \RuntimeException::class);
        Assert::same($storage->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    public function forceStateReportsAFullyPopulatedTransition(): void
    {
        if ($this->storage === null) {
            return;
        }

        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $transition = $this->storage->forceState(self::NAME, CircuitState::Open, $now);

        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->breakerName(), self::NAME);
        Assert::same($transition->from(), CircuitState::Closed);
        Assert::same($transition->to(), CircuitState::Open);
        Assert::same($transition->reason(), TransitionReason::ForcedOpen);
        Assert::same($transition->occurredAt(), $now);
        Assert::same($transition->state()->state(), CircuitState::Open);

        // Forcing the state already in effect resets counters but is not a
        // state change - no transition may be reported.
        Assert::null($this->storage->forceState(self::NAME, CircuitState::Open, $now));

        $halfOpen = $this->storage->forceState(self::NAME, CircuitState::HalfOpen, $now);
        Assert::instanceOf($halfOpen, CircuitTransition::class);
        Assert::same($halfOpen->reason(), TransitionReason::ForcedHalfOpen);

        $closed = $this->storage->forceState(self::NAME, CircuitState::Closed, $now);
        Assert::instanceOf($closed, CircuitTransition::class);
        Assert::same($closed->reason(), TransitionReason::ForcedClosed);
    }

    public function outcomeWithoutAStateChangeReportsNoTransition(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 5, window: 10);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $result = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $now);

        Assert::same($result->state()->state(), CircuitState::Closed);
        Assert::null($result->transition());
    }

    /**
     * `admit()` transitions state too (`Open -> HalfOpen` once the cooldown
     * elapsed, probe-slot bookkeeping). That write must reach APCu, not just
     * the local copy of the entry: another worker only ever sees what was
     * committed.
     */
    public function admitPersistsItsOwnTransition(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $this->storage->forceState(self::NAME, CircuitState::Open, $now);

        $admission = $this->admitOn(
            $this->storage,
            self::NAME,
            $config,
            $now->modify('+31 seconds'),
        )->admission();

        Assert::same($admission, Admission::Probe);

        $fresh = new ApcuStorage();
        Assert::same($fresh->snapshot(self::NAME)->state(), CircuitState::HalfOpen);
    }

    /**
     * A breaker literally named `lock:<x>` must not land on the APCu key the
     * lock of breaker `<x>` uses. With a single shared keyspace the entry
     * write of the first breaker replaces the second one's lock value, and
     * every subsequent operation on `<x>` exhausts its spin budget instead of
     * running.
     */
    public function entryAndLockKeyspacesDoNotCollide(): void
    {
        if ($this->storage === null) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $shadow = 'lock:' . self::NAME;

        $this->recordOn($this->storage, $shadow, Outcome::Failure, $config, $now)->state();

        Assert::same($this->storage->snapshot($shadow)->state(), CircuitState::Open);
        Assert::same($this->storage->snapshot(self::NAME)->state(), CircuitState::Closed);

        // The shadowed breaker must still be operable, not lock-starved.
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $now)->state();

        Assert::same($record->state(), CircuitState::Open);
    }

    /**
     * `apcu_store()` returns `false` when the shared memory segment is full.
     * Ignoring it reports a committed transition that was never written -
     * losing a `Closed -> Open` transition means the breaker silently fails
     * open. It must surface as a storage failure instead.
     */
    public function failedEntryWriteIsNotReportedAsACommit(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-store-fail:';
        $storage = new ApcuStorage(keyPrefix: $prefix);
        $config = $this->config(failures: 1, window: 1);
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        ApcuStoreFailure::$failKeySubstring = $prefix . 'entry:';
        $caught = null;

        try {
            $this->recordOn($storage, self::NAME, Outcome::Failure, $config, $now)->state();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            ApcuStoreFailure::reset();
        }

        Assert::instanceOf($caught, \RuntimeException::class);
        Assert::string($caught->getMessage())->contains('Unable to store APCu entry');
        Assert::same($storage->snapshot(self::NAME)->state(), CircuitState::Closed);
    }

    /**
     * The lock is a lease, not a mutex: a critical section that outlives it
     * may be racing a second worker that already took over. Committing then
     * would drop that worker's write, so the commit must refuse.
     */
    public function commitRefusesOnceTheLockLeaseIsLost(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-lease-lost:';
        $storage = new ApcuStorage(keyPrefix: $prefix);
        $commit = new \ReflectionMethod(ApcuStorage::class, 'commit');
        $entry = new \ReflectionMethod(ApcuStorage::class, 'entryFor');
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        /** @var array<string, mixed> $fresh */
        $fresh = $entry->invoke($storage, self::NAME, $now);

        // No lock held at all: whatever token we present is not the live one.
        $caught = null;

        try {
            $commit->invoke($storage, self::NAME, 4242, $fresh);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, \RuntimeException::class);
        Assert::string($caught->getMessage())->contains('lock lease');
        Assert::false(apcu_exists($prefix . 'entry:' . self::NAME));
    }

    public function acquiringReleasedMarkerRefreshesLockLease(): void
    {
        if ($this->storage === null) {
            return;
        }

        $prefix = 'circuit-breaker-lease-test:';
        $lockKey = $prefix . 'lock:' . self::NAME;
        $storage = new ApcuStorage(keyPrefix: $prefix, lockMaxAttempts: 1, lockRetryMicros: 1);
        $lock = new \ReflectionMethod(ApcuStorage::class, 'lock');
        $unlock = new \ReflectionMethod(ApcuStorage::class, 'unlock');
        apcu_store($lockKey, 0, 1);
        usleep(700_000);

        $token = $lock->invoke($storage, self::NAME);
        Assert::true(is_int($token));
        usleep(500_000);

        Assert::same(apcu_fetch($lockKey), $token);

        $unlock->invoke($storage, self::NAME, $token);
        apcu_delete($lockKey);
    }

    /**
     * Forks real processes that all race to occupy the same `HalfOpen` probe
     * slots, instead of asserting on a single sequential process. A
     * single-process test cannot distinguish a correct `apcu_add`
     * compare-and-set from one whose success/failure branches are swapped,
     * because the lock key gets set either way — only real concurrent
     * contention exercises the race.
     */
    public function neverAdmitsMoreProbesThanProbeLimitUnderRealForkContention(): void
    {
        if ($this->storage === null || !extension_loaded('pcntl')) {
            return;
        }

        $probeLimit = 3;
        $workers = 30;
        $config = new BreakerConfig(
            name: self::NAME,
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: $probeLimit,
        );
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $this->storage->forceState(self::NAME, CircuitState::HalfOpen, $now);

        $resultFile = tempnam(sys_get_temp_dir(), 'cb-fork-stress-');
        Assert::true($resultFile !== false);

        apcu_store('test-barrier:ready', 0);
        apcu_store('test-barrier:go', 0);

        $pids = [];
        for ($i = 0; $i < $workers; ++$i) {
            $pid = pcntl_fork();
            Assert::true($pid !== -1);

            if ($pid === 0) {
                $storage = new ApcuStorage();
                apcu_inc('test-barrier:ready');
                while (apcu_fetch('test-barrier:go') !== 1) {
                    usleep(200);
                }

                $admission = $this->admitOn($storage, self::NAME, $config, $now)->admission();
                if ($admission === Admission::Probe) {
                    file_put_contents($resultFile, "1\n", \FILE_APPEND | \LOCK_EX);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        while (apcu_fetch('test-barrier:ready') < $workers) {
            usleep(200);
        }
        apcu_store('test-barrier:go', 1);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $admitted = substr_count((string) file_get_contents($resultFile), "1\n");
        unlink($resultFile);

        Assert::true($admitted <= $probeLimit);
    }

    private function config(int $failures, int $window): BreakerConfig
    {
        return new BreakerConfig(
            name: self::NAME,
            failureThreshold: Ratio::of(failures: $failures, window: $window, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
