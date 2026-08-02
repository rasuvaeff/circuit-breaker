<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Integration;

use Predis\Client;
use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\LuaScripts;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RedisStorage::class)]
#[Covers(PredisScriptRunner::class)]
// LuaScripts' bodies only run as real Lua inside Redis (a PHP function
// cannot execute inside a Redis script, see AGENTS.md golden rule 3) - this
// is the only place that happens with predis.
#[Covers(LuaScripts::class)]
final class RedisIntegrationTest
{
    use StorageCalls;

    private const string NAME = 'it';

    private RedisStorage $storage;
    private Client $client;
    private \DateTimeImmutable $base;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $port = getenv('REDIS_PORT');
        $this->client = new Client([
            'host' => $host,
            'port' => $port === false || $port === '' ? 6379 : (int) $port,
        ]);
        $this->client->flushdb();
        $this->storage = new RedisStorage(new PredisScriptRunner($this->client), useServerTime: false);
        $this->base = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }

    public function admitAllowsInClosed(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        Assert::same($this->admitOn($this->storage, self::NAME, $this->config(), $this->base)->admission(), Admission::Allowed);
    }

    public function serverTimeIsUsedByDefault(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $storage = new RedisStorage(new PredisScriptRunner($this->client));
        $before = new \DateTimeImmutable('-2 seconds');
        $storage->forceState('server-time', CircuitState::Open, $this->base);
        $openedAt = $storage->snapshot('server-time')->openedAt();

        Assert::true($openedAt >= $before);
        Assert::true($openedAt <= new \DateTimeImmutable('+2 seconds'));
    }

    public function admitUsesServerTimeByDefault(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $storage = new RedisStorage(new PredisScriptRunner($this->client));
        $config = $this->config(cooldown: Duration::hours(1));
        $storage->forceState('server-time-admit', CircuitState::Open, $this->base);

        $admission = $this->admitOn(
            $storage,
            'server-time-admit',
            $config,
            new \DateTimeImmutable('2100-01-01T00:00:00+00:00'),
        )->admission();

        Assert::same($admission, Admission::Rejected);
    }

    public function closedTransitionsToOpenAfterFailureThreshold(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 2, window: 5);

        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->failures(), 2);
    }

    public function successesDiluteTheWindowWithoutCountingAsFailures(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 2, window: 5);

        $this->recordOn($this->storage, self::NAME, Outcome::Success, $config, $this->base)->state();
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->successes(), 1);
        Assert::same($record->failures(), 1);
    }

    public function ignoredOutcomeDoesNotEnterTheWindow(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 5);
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Ignored, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->successes(), 0);
        Assert::same($record->failures(), 0);
    }

    public function ringBufferIsCappedByWindow(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 3, window: 3);

        for ($i = 0; $i < 5; ++$i) {
            $this->recordOn($this->storage, self::NAME, Outcome::Success, $config, $this->base)->state();
        }

        Assert::same($this->storage->snapshot(self::NAME)->successes(), 3);
    }

    public function entriesOlderThanWithinAreEvicted(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 2, window: 10, within: Duration::seconds(5));
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base->modify('+10 seconds'))->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->failures(), 1);
    }

    public function entryExactlyAtWithinBoundaryIsRetained(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 2, window: 10, within: Duration::seconds(5));
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        $record = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Failure,
            $config,
            $this->base->modify('+5 seconds'),
        )->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->failures(), 2);
    }

    public function admitRejectsInOpenBeforeCooldown(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        $admission = $this->admitOn($this->storage, self::NAME, $config, $this->base->modify('+10 seconds'))->admission();

        Assert::same($admission, Admission::Rejected);
        Assert::same($this->storage->snapshot(self::NAME)->rejected(), 1);
    }

    public function admitTransitionsToHalfOpenAfterCooldown(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        $admission = $this->admitOn($this->storage, self::NAME, $config, $this->base->modify('+31 seconds'))->admission();

        Assert::same($admission, Admission::Probe);
        Assert::same($this->storage->snapshot(self::NAME)->state(), CircuitState::HalfOpen);
    }

    public function admitRejectsBeyondProbeLimit(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 2);
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');

        $first = $this->admitOn($this->storage, self::NAME, $config, $probeTime)->admission();
        $second = $this->admitOn($this->storage, self::NAME, $config, $probeTime)->admission();
        $third = $this->admitOn($this->storage, self::NAME, $config, $probeTime)->admission();

        Assert::same($first, Admission::Probe);
        Assert::same($second, Admission::Probe);
        Assert::same($third, Admission::Rejected);
    }

    public function abandonedProbeSlotIsReclaimedAfterTimeout(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState(self::NAME, CircuitState::Open, $this->base);
        $probeTime = $this->base->modify('+30 seconds');

        Assert::same($this->admitOn($this->storage, self::NAME, $config, $probeTime)->admission(), Admission::Probe);
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime->modify('+4 seconds'))->admission(),
            Admission::Rejected,
        );
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime->modify('+5 seconds'))->admission(),
            Admission::Probe,
        );
    }

    public function freshProbeAfterIdleHalfOpenDoesNotInheritExpiredGeneration(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(1),
            successThreshold: 3,
            probeLimit: 1,
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState(self::NAME, CircuitState::Open, $this->base);
        $firstProbeAt = $this->base->modify('+1 second');
        $this->admitOn($this->storage, self::NAME, $config, $firstProbeAt)->admission();
        $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $firstProbeAt,
            Admission::Probe,
            $firstProbeAt,
        )->state();
        $later = $firstProbeAt->modify('+10 seconds');

        $first = $this->admitOn($this->storage, self::NAME, $config, $later)->admission();
        $second = $this->admitOn($this->storage, self::NAME, $config, $later)->admission();

        Assert::same($first, Admission::Probe);
        Assert::same($second, Admission::Rejected);
    }

    public function serverTimeModeFencesOutcomeFromReclaimedProbe(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $storage = new RedisStorage(new PredisScriptRunner($this->client));
        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::millis(5),
            probeTimeout: Duration::millis(20),
        );
        $now = new \DateTimeImmutable();
        $storage->forceState('server-fencing', CircuitState::Open, $now);
        usleep(20_000);
        $firstProbeAt = new \DateTimeImmutable();
        Assert::same(
            $this->admitOn($storage, 'server-fencing', $config, $firstProbeAt, 'first-attempt')->admission(),
            Admission::Probe,
        );
        usleep(50_000);
        $secondProbeAt = new \DateTimeImmutable();
        Assert::same(
            $this->admitOn($storage, 'server-fencing', $config, $secondProbeAt, 'second-attempt')->admission(),
            Admission::Probe,
        );

        $stale = $this->recordOn(
            $storage,
            'server-fencing',
            Outcome::Failure,
            $config,
            new \DateTimeImmutable(),
            Admission::Probe,
            $firstProbeAt,
            'first-attempt',
        )->state();
        $closed = $this->recordOn(
            $storage,
            'server-fencing',
            Outcome::Success,
            $config,
            new \DateTimeImmutable(),
            Admission::Probe,
            $secondProbeAt,
            'second-attempt',
        )->state();

        Assert::same($stale->state(), CircuitState::HalfOpen);
        Assert::same($closed->state(), CircuitState::Closed);
    }

    public function outcomeFromExpiredProbeGenerationIsIgnored(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState(self::NAME, CircuitState::Open, $this->base);
        $firstProbeAt = $this->base->modify('+30 seconds');
        $secondProbeAt = $firstProbeAt->modify('+5 seconds');
        $this->admitOn($this->storage, self::NAME, $config, $firstProbeAt, 'first-probe')->admission();
        $this->admitOn($this->storage, self::NAME, $config, $secondProbeAt, 'second-probe')->admission();

        $stale = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Failure,
            $config,
            $secondProbeAt,
            Admission::Probe,
            $firstProbeAt,
            'first-probe',
        )->state();
        $closed = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $secondProbeAt,
            Admission::Probe,
            $secondProbeAt,
            'second-probe',
        )->state();

        Assert::same($stale->state(), CircuitState::HalfOpen);
        Assert::same($closed->state(), CircuitState::Closed);
    }

    public function halfOpenClosesAfterSuccessThreshold(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 2);
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-1')->admission();

        $first = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-1',
        )->state();
        Assert::same($first->state(), CircuitState::HalfOpen);

        $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-2')->admission();
        $second = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-2',
        )->state();

        Assert::same($second->state(), CircuitState::Closed);
        Assert::same($second->rejected(), 0);
    }

    public function lateProbeFailureReopensAfterSiblingProbeClosedCircuit(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(
            failures: 5,
            window: 10,
            cooldown: Duration::seconds(30),
            probeLimit: 2,
        );
        $this->storage->forceState(self::NAME, CircuitState::Open, $this->base);
        $probeTime = $this->base->modify('+30 seconds');

        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-1')->admission(),
            Admission::Probe,
        );
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-2')->admission(),
            Admission::Probe,
        );

        $closed = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-1',
        )->state();
        $reopened = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Failure,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-2',
        )->state();

        Assert::same($closed->state(), CircuitState::Closed);
        Assert::same($reopened->state(), CircuitState::Open);
        Assert::same($reopened->failures(), 0);
    }

    /**
     * Lua parity for `InMemoryStorageTest::lateAllowedOutcomeIsNotCountedAsHalfOpenProbe`
     * - an outcome admitted as `Allowed` while the breaker was `Closed` must
     * not be treated as a probe of the `HalfOpen` generation it lands in.
     */
    public function lateAllowedOutcomeIsNotCountedAsHalfOpenProbe(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 1);
        $admittedAt = $this->base;
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $admittedAt, 'allowed-1')->admission(),
            Admission::Allowed,
        );

        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-1')->admission(),
            Admission::Probe,
        );

        $late = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Allowed,
            $admittedAt,
            'allowed-1',
        )->state();

        Assert::same($late->state(), CircuitState::HalfOpen);
        Assert::same($late->successes(), 0);

        // The real probe still holds the only slot.
        Assert::same(
            $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-2')->admission(),
            Admission::Rejected,
        );
    }

    public function halfOpenReopensOnFailure(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-1')->admission();

        $record = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Failure,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-1',
        )->state();

        Assert::same($record->state(), CircuitState::Open);
    }

    public function halfOpenIgnoredOutcomeReleasesSlotWithoutTransition(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 1);
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, self::NAME, $config, $probeTime, 'probe-1')->admission();

        $record = $this->recordOn(
            $this->storage,
            self::NAME,
            Outcome::Ignored,
            $config,
            $probeTime,
            Admission::Probe,
            $probeTime,
            'probe-1',
        )->state();
        Assert::same($record->state(), CircuitState::HalfOpen);

        Assert::same($this->admitOn($this->storage, self::NAME, $config, $probeTime)->admission(), Admission::Probe);
    }

    public function forceStateResetsCounters(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();
        Assert::same($this->storage->snapshot(self::NAME)->state(), CircuitState::Open);

        $this->storage->forceState(self::NAME, CircuitState::Closed, $this->base);
        $record = $this->storage->snapshot(self::NAME);

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->failures(), 0);
        Assert::same($record->rejected(), 0);
    }

    public function keysAreIsolated(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $this->recordOn($this->storage, 'a', Outcome::Failure, $config, $this->base)->state();

        Assert::same($this->storage->snapshot('a')->state(), CircuitState::Open);
        Assert::same($this->storage->snapshot('b')->state(), CircuitState::Closed);
    }

    public function scriptCacheFlushIsSurvivedViaEvalFallback(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $client = new Client([
            'host' => $host,
            'port' => $port === false || $port === '' ? 6379 : (int) $port,
        ]);
        $client->script('flush');

        $admission = $this->admitOn($this->storage, self::NAME, $this->config(), $this->base)->admission();

        Assert::same($admission, Admission::Allowed);
    }

    private function config(
        int $failures = 5,
        int $window = 10,
        ?Duration $within = null,
        ?Duration $cooldown = null,
        int $successThreshold = 1,
        int $probeLimit = 1,
        ?Duration $probeTimeout = null,
    ): BreakerConfig {
        return new BreakerConfig(
            name: self::NAME,
            failureThreshold: Ratio::of(failures: $failures, window: $window, within: $within ?? Duration::seconds(60)),
            cooldown: $cooldown ?? Duration::seconds(30),
            successThreshold: $successThreshold,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: $probeLimit,
            probeTimeout: $probeTimeout,
        );
    }
}
