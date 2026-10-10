<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\AdmissionResult;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitBreakerInterface;
use Rasuvaeff\CircuitBreaker\CircuitObserver;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\CircuitSnapshotObserver;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Metrics;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\OutcomeResult;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\CircuitBreaker\Storage;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\CircuitBreaker\StorageOperation;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(CircuitBreaker::class)]
// StorageOperation is the enum CircuitBreaker::storageOperation() labels every
// wrapped Storage failure with (see AGENTS.md golden rule 3, StorageFailure);
// this test class is the only place that failure-wrapping path runs.
#[Covers(StorageOperation::class)]
final class CircuitBreakerTest
{
    use StorageCalls;

    private InMemoryStorage $storage;
    private FakeClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = new FakeClock();
    }

    public function callReturnsCallbackResultInClosed(): void
    {
        $cb = $this->breaker();

        $result = $cb->call(static fn(): string => 'ok');

        Assert::same($result, 'ok');
        Assert::same($cb->state(), CircuitState::Closed);
    }

    public function normalFailureResultOpensCircuitAndIsReturned(): void
    {
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            classifyResult: static fn(mixed $result): Outcome => $result === 'degraded'
                ? Outcome::Failure
                : Outcome::Success,
        );

        Assert::same($cb->call(static fn(): string => 'degraded'), 'degraded');
        Assert::same($cb->state(), CircuitState::Open);
    }

    public function observerReceivesCommittedTransition(): void
    {
        $observer = Understudy::for(CircuitObserver::class);
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        // Armed before the run; verified by the UnderstudyPlugin after the body.
        expect(fn() => $observer->onTransition(Arg::any()));

        $this->callAndSwallow($cb);

        $events = $this->transitionsReceivedBy($observer);

        Assert::same($events[0]->from(), CircuitState::Closed);
        Assert::same($events[0]->to(), CircuitState::Open);
        Assert::same($events[0]->reason()->value, 'failure-threshold-reached');
        Assert::same($events[0]->state()->state(), $cb->state());
    }

    /**
     * `admit()` commits a transition of its own (`Open -> HalfOpen` once the
     * cooldown elapsed). `call()` must publish that one too, not only the
     * transitions that come out of `recordOutcome()`.
     */
    public function observerReceivesTheCooldownTransitionCommittedByAdmit(): void
    {
        $observer = Understudy::for(CircuitObserver::class);
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        // Armed before the run; verified by the UnderstudyPlugin after the body.
        expect(fn() => $observer->onTransition(Arg::any()))->times(3);

        $this->callAndSwallow($cb);
        $this->clock->advanceMs(31_000);
        $cb->call(static fn(): string => 'ok');

        $events = $this->transitionsReceivedBy($observer);

        Assert::same(
            array_map(static fn(CircuitTransition $t): string => $t->reason()->value, $events),
            [
                'failure-threshold-reached',
                'cooldown-elapsed',
                'probe-succeeded',
            ],
        );
        Assert::same($events[1]->from(), CircuitState::Open);
        Assert::same($events[1]->to(), CircuitState::HalfOpen);
    }

    public function forcedTransitionsAreReportedToTheObserver(): void
    {
        $observer = Understudy::for(CircuitObserver::class);
        $cb = $this->breaker(
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        // Armed before the run; verified by the UnderstudyPlugin after the body.
        expect(fn() => $observer->onTransition(Arg::any()))->times(2);

        $cb->forceOpen();
        $cb->forceClosed();

        $events = $this->transitionsReceivedBy($observer);

        Assert::same(
            array_map(static fn(CircuitTransition $t): string => $t->reason()->value, $events),
            ['forced-open', 'forced-closed'],
        );
        Assert::same($events[0]->to(), CircuitState::Open);
        Assert::same($events[1]->from(), CircuitState::Open);
        Assert::same($events[1]->to(), CircuitState::Closed);
    }

    public function forcingTheStateAlreadyInEffectReportsNothing(): void
    {
        $observer = Understudy::for(CircuitObserver::class);
        $cb = $this->breaker(
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        $cb->forceClosed();

        Assert::same($this->transitionsReceivedBy($observer), []);
    }

    public function implementsTheBreakerInterface(): void
    {
        Assert::instanceOf($this->breaker(), CircuitBreakerInterface::class);
    }

    public function clockDefaultsToTheSystemClock(): void
    {
        $cb = new CircuitBreaker(
            config: BreakerConfig::forRemoteApi(
                name: 'svc',
                failures: 1,
                window: 1,
                within: Duration::seconds(60),
                cooldown: Duration::seconds(30),
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $this->storage,
        );

        $before = new \DateTimeImmutable();
        $cb->forceOpen();
        $after = new \DateTimeImmutable();

        $openedAt = $cb->metrics()->openedAt();
        Assert::true($openedAt >= $before);
        Assert::true($openedAt <= $after);
    }

    public function observerWithoutErrorHandlerDiscardsObserverFailures(): void
    {
        $observer = Understudy::for(CircuitObserver::class);
        when(fn() => $observer->onTransition(Arg::any()))->throws(new \RuntimeException('gauge down'));
        $cb = $this->breaker(observer: $observer);

        $cb->forceOpen();

        Assert::same($cb->state(), CircuitState::Open);
    }

    public function errorHandlerWithoutObserverIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('Observer error handler requires an observer');

        $this->breaker(observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {});
    }

    public function publishStateHandsTheCurrentSnapshotToASnapshotObserver(): void
    {
        $observer = Understudy::for(CircuitSnapshotObserver::class);
        $cb = $this->breaker(failures: 1, window: 1, observer: $observer);
        $this->callAndSwallow($cb);

        expect(fn() => $observer->onSnapshot(Arg::any(), Arg::any()))->times(1);

        $cb->publishState();

        $calls = Understudy::calls(fn() => $observer->onSnapshot(Arg::any(), Arg::any()));
        Assert::same($calls[0]->args[0], 'svc');
        $metrics = $calls[0]->args[1];
        Assert::instanceOf($metrics, Metrics::class);
        Assert::same($metrics->state(), CircuitState::Open);
        Assert::same($metrics->failures(), 1);
    }

    public function publishStateReportsAClosedBreakerThatNeverTransitioned(): void
    {
        $observer = Understudy::for(CircuitSnapshotObserver::class);
        $cb = $this->breaker(observer: $observer);

        expect(fn() => $observer->onSnapshot(Arg::any(), Arg::any()))->times(1);
        expect(fn() => $observer->onTransition(Arg::any()))->times(0);

        $cb->publishState();

        $calls = Understudy::calls(fn() => $observer->onSnapshot(Arg::any(), Arg::any()));
        Assert::same($calls[0]->args[1]->state(), CircuitState::Closed);
    }

    public function publishStateWithAPlainObserverDoesNotTouchStorage(): void
    {
        $storage = Understudy::for(Storage::class);
        $observer = Understudy::for(CircuitObserver::class);
        $cb = new CircuitBreaker(
            config: BreakerConfig::forRemoteApi(
                name: 'svc',
                failures: 1,
                window: 1,
                within: Duration::seconds(60),
                cooldown: Duration::seconds(30),
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: $this->clock,
            observer: $observer,
        );

        expect(fn() => $storage->snapshot(Arg::any()))->times(0);

        $cb->publishState();
    }

    public function publishStateWithoutObserverIsANoOp(): void
    {
        $storage = Understudy::for(Storage::class);
        $cb = new CircuitBreaker(
            config: BreakerConfig::forRemoteApi(
                name: 'svc',
                failures: 1,
                window: 1,
                within: Duration::seconds(60),
                cooldown: Duration::seconds(30),
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: $this->clock,
        );

        expect(fn() => $storage->snapshot(Arg::any()))->times(0);

        $cb->publishState();
    }

    public function publishStatePropagatesObserverFailures(): void
    {
        $observer = Understudy::for(CircuitSnapshotObserver::class);
        when(fn() => $observer->onSnapshot(Arg::any(), Arg::any()))->throws(new \RuntimeException('gauge down'));
        $cb = $this->breaker(
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        Expect::exception(\RuntimeException::class)->withMessageContaining('gauge down');

        $cb->publishState();
    }

    public function storageFailureAfterSuccessfulCallbackIsNotRecordedAsDownstreamFailure(): void
    {
        $storage = Understudy::for(Storage::class);
        when(fn() => $storage->admit(Arg::any(), Arg::any(), Arg::any(), Arg::any()))
            ->returns(new AdmissionResult(Admission::Allowed));
        when(fn() => $storage->recordOutcome(
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
        ))->throws(new \RuntimeException('storage unavailable'));
        $cb = new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: $this->clock,
        );
        $caught = null;

        try {
            $cb->call(static fn(): string => 'ok');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, StorageFailure::class);
        Assert::same($caught->operation, 'recordOutcome');
        Assert::instanceOf($caught->getPrevious(), \RuntimeException::class);
        Assert::same($caught->getPrevious()?->getMessage(), 'storage unavailable');
        Assert::null($caught->downstreamOutcome);
        Assert::same(count($this->recordedAttemptIds($storage)), 1);
        $firstAdmits = $this->admitAttemptIds($storage);
        Assert::same(preg_match('/^[a-f0-9]{32}:1$/', $firstAdmits[0]), 1);
        Assert::same($this->recordedAttemptIds($storage)[0], $firstAdmits[0]);

        try {
            $cb->call(static fn(): string => 'ok');
        } catch (StorageFailure) {
            // expected
        }

        $admits = $this->admitAttemptIds($storage);
        Assert::same(preg_match('/^[a-f0-9]{32}:2$/', $admits[1]), 1);
        Assert::same($this->recordedAttemptIds($storage)[1], $admits[1]);
    }

    /**
     * When the callback has ALREADY thrown and the storage fails while
     * recording that outcome, the StorageFailure outranks the downstream
     * exception (an infrastructure problem is not a downstream verdict) and
     * fallback is not invoked - but the downstream exception must stay
     * reachable via the wrapper's downstreamOutcome property, or the caller
     * can neither log the 503 nor react to it.
     */
    public function storageFailureWhileRecordingAThrownOutcomePreservesTheDownstreamException(): void
    {
        $storage = Understudy::for(Storage::class);
        when(fn() => $storage->admit(Arg::any(), Arg::any(), Arg::any(), Arg::any()))
            ->returns(new AdmissionResult(Admission::Allowed));
        when(fn() => $storage->recordOutcome(
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
        ))->throws(new \RuntimeException('storage unavailable'));
        $cb = new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: $this->clock,
        );
        $downstream = new \DomainException('downstream 503');
        $fallbackInvoked = false;
        $caught = null;

        try {
            $cb->call(
                callback: static fn(): string => throw $downstream,
                fallback: static function (\Throwable $e) use (&$fallbackInvoked): string {
                    $fallbackInvoked = true;

                    return 'fallback';
                },
            );
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, StorageFailure::class);
        Assert::same($caught->operation, 'recordOutcome');
        Assert::same($caught->getPrevious()?->getMessage(), 'storage unavailable');
        Assert::same($caught->downstreamOutcome, $downstream);
        Assert::false($fallbackInvoked);
    }
    #[DataProvider('storageFailureCarriesTheOperationThatFailedProvider')]
    public function storageFailureCarriesTheOperationThatFailed(
        StorageOperation $throwingOperation,
        \Closure $trigger,
    ): void {
        $storage = Understudy::for(Storage::class);
        $closed = new StateRecord(CircuitState::Closed, new \DateTimeImmutable('@0'), 0, 0, 0);
        when(fn() => $storage->admit(Arg::any(), Arg::any(), Arg::any(), Arg::any()))
            ->returns(new AdmissionResult(Admission::Allowed));
        when(fn() => $storage->recordOutcome(
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
            Arg::any(),
        ))->returns(new OutcomeResult($closed));
        when(fn() => $storage->snapshot(Arg::any()))->returns($closed);
        when(fn() => $storage->forceState(Arg::any(), Arg::any(), Arg::any()))->returns(null);
        // A later stub for the same call wins over the answering one above.
        $failure = new \RuntimeException('storage unavailable');
        match ($throwingOperation) {
            StorageOperation::Admit => when(
                fn() => $storage->admit(Arg::any(), Arg::any(), Arg::any(), Arg::any()),
            )->throws($failure),
            StorageOperation::RecordOutcome => when(
                fn() => $storage->recordOutcome(
                    Arg::any(),
                    Arg::any(),
                    Arg::any(),
                    Arg::any(),
                    Arg::any(),
                    Arg::any(),
                    Arg::any(),
                ),
            )->throws($failure),
            StorageOperation::Snapshot => when(
                fn() => $storage->snapshot(Arg::any()),
            )->throws($failure),
            StorageOperation::ForceState => when(
                fn() => $storage->forceState(Arg::any(), Arg::any(), Arg::any()),
            )->throws($failure),
        };
        $cb = new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: $this->clock,
        );
        $caught = null;

        try {
            $trigger($cb);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, StorageFailure::class);
        Assert::same($caught->operation, $throwingOperation->value);
    }

    /** @return iterable<string, array{StorageOperation, \Closure(CircuitBreaker): mixed}> */
    public static function storageFailureCarriesTheOperationThatFailedProvider(): iterable
    {
        yield 'admit' => [
            StorageOperation::Admit,
            static fn(CircuitBreaker $cb): string => $cb->call(static fn(): string => 'ok'),
        ];
        yield 'snapshot' => [
            StorageOperation::Snapshot,
            static fn(CircuitBreaker $cb): CircuitState => $cb->state(),
        ];
        yield 'forceState' => [
            StorageOperation::ForceState,
            static fn(CircuitBreaker $cb): mixed => $cb->forceOpen(),
        ];
    }

    public function failureIsTimestampedWhenCallbackCompletes(): void
    {
        $cb = $this->breaker(failures: 1, window: 1, cooldown: Duration::seconds(5));

        try {
            $cb->call(function (): never {
                $this->clock->advanceMs(10_000);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Assert::same($cb->metrics()->openedAt(), $this->clock->now());
        Assert::false($cb->canCall());
    }

    public function callRethrowsOriginalExceptionOnFailure(): void
    {
        $cb = $this->breaker(failures: 5, window: 10);

        Expect::exception(\RuntimeException::class)->withMessageContaining('boom');

        $cb->call(static function (): never {
            throw new \RuntimeException('boom');
        });
    }

    public function callOpensCircuitAfterFailureThreshold(): void
    {
        $cb = $this->breaker(failures: 2, window: 5);

        $this->callAndSwallow($cb);
        $this->callAndSwallow($cb);

        Assert::same($cb->state(), CircuitState::Open);
    }

    public function fallbackIsInvokedOnFailureAndReceivesTheException(): void
    {
        $cb = $this->breaker(failures: 5, window: 10);
        $seen = null;

        $result = $cb->call(
            callback: static function (): never {
                throw new \RuntimeException('boom');
            },
            fallback: static function (\Throwable $e) use (&$seen): string {
                $seen = $e;

                return 'fallback';
            },
        );

        Assert::same($result, 'fallback');
        Assert::instanceOf($seen, \RuntimeException::class);
    }

    public function ignoredOutcomeIsRethrownEvenWithFallback(): void
    {
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            isFailure: static fn(\Throwable $e): bool => false,
        );

        Expect::exception(\InvalidArgumentException::class);

        $cb->call(
            callback: static function (): never {
                throw new \InvalidArgumentException('caller bug');
            },
            fallback: static fn(\Throwable $e): string => 'should not be reached',
        );
    }

    public function rejectedCallNeverInvokesCallback(): void
    {
        $cb = $this->breaker();
        $cb->forceOpen();
        $invoked = false;

        try {
            $cb->call(static function () use (&$invoked): string {
                $invoked = true;

                return 'ok';
            });
            Assert::true(actual: false);
        } catch (CircuitOpenException) {
            // expected
        }

        Assert::false($invoked);
    }

    public function rejectedCallUsesFallbackWithCircuitOpenException(): void
    {
        $cb = $this->breaker();
        $cb->forceOpen();
        $seen = null;

        $result = $cb->call(
            callback: static fn(): string => 'never',
            fallback: static function (\Throwable $e) use (&$seen): string {
                $seen = $e;

                return 'degraded';
            },
        );

        Assert::same($result, 'degraded');
        Assert::instanceOf($seen, CircuitOpenException::class);
    }

    public function halfOpenRejectionReportsFutureProbeLeaseDeadline(): void
    {
        $probeTimeout = Duration::seconds(20);
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            successThreshold: 2,
            probeTimeout: $probeTimeout,
        );
        $cb->forceOpen();
        $this->clock->advanceMs(31_000);
        $this->admitOn($this->storage, 'svc', $this->configForProbeTimeout($probeTimeout), $this->clock->now())->admission();
        $caught = null;

        try {
            $cb->call(static fn(): string => 'never');
        } catch (CircuitOpenException $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, CircuitOpenException::class);
        Assert::same(
            $caught->retryAfter->format('U.u'),
            $this->clock->now()->modify('+20 seconds')->format('U.u'),
        );
    }

    public function closedSnapshotAfterConcurrentRecoveryRetriesImmediately(): void
    {
        $cb = $this->breaker();
        $retryAfter = (new \ReflectionMethod(CircuitBreaker::class, 'retryAfter'))->invoke(
            $cb,
            new StateRecord(CircuitState::Closed, $this->clock->now(), 0, 0, 0),
        );

        Assert::instanceOf($retryAfter, \DateTimeImmutable::class);
        Assert::same($retryAfter, $this->clock->now());
    }

    public function canCallIsTrueInClosed(): void
    {
        $cb = $this->breaker();

        Assert::true($cb->canCall());
    }

    public function canCallIsTrueInHalfOpen(): void
    {
        $cb = $this->breaker(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 2);
        $this->callAndSwallow($cb);
        $this->clock->advanceMs(31_000);
        $cb->call(static fn(): string => 'probe');
        Assert::same($cb->state(), CircuitState::HalfOpen);

        Assert::true($cb->canCall());
    }

    public function canCallIsTrueExactlyAtCooldownBoundary(): void
    {
        $cb = $this->breaker(cooldown: Duration::seconds(30));
        $cb->forceOpen();

        $this->clock->advanceMs(30_000);

        Assert::true($cb->canCall());
    }

    public function canCallIsFalseInOpenBeforeCooldownAndTrueAfter(): void
    {
        $cb = $this->breaker(cooldown: Duration::seconds(30));
        $cb->forceOpen();

        Assert::false($cb->canCall());

        $this->clock->advanceMs(31_000);

        Assert::true($cb->canCall());
    }

    public function halfOpenClosesAfterSuccessThreshold(): void
    {
        $cb = $this->breaker(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 2);
        $this->callAndSwallow($cb);
        Assert::same($cb->state(), CircuitState::Open);

        $this->clock->advanceMs(31_000);

        $cb->call(static fn(): string => 'probe 1');
        Assert::same($cb->state(), CircuitState::HalfOpen);

        $cb->call(static fn(): string => 'probe 2');
        Assert::same($cb->state(), CircuitState::Closed);
    }

    public function halfOpenReopensOnProbeFailure(): void
    {
        $cb = $this->breaker(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->callAndSwallow($cb);
        $this->clock->advanceMs(31_000);

        $this->callAndSwallow($cb);

        Assert::same($cb->state(), CircuitState::Open);
    }

    public function forceClosedResetsCounters(): void
    {
        $cb = $this->breaker(failures: 1, window: 1);
        $this->callAndSwallow($cb);
        Assert::same($cb->state(), CircuitState::Open);

        $cb->forceClosed();

        Assert::same($cb->state(), CircuitState::Closed);
        Assert::same($cb->metrics()->failures(), 0);
    }

    public function metricsMirrorTheCurrentSnapshot(): void
    {
        $cb = $this->breaker(failures: 5, window: 10);
        $this->callAndSwallow($cb);

        $metrics = $cb->metrics();

        Assert::same($metrics->state(), CircuitState::Closed);
        Assert::same($metrics->failures(), 1);
    }

    /**
     * `callAlwaysThrowsInOpen` (see rasuvaeff/circuit-breaker plan's property
     * table): whatever the cooldown, an `Open` breaker without a fallback
     * always rejects via `CircuitOpenException` and never invokes the
     * callback.
     */
    #[Property(runs: 100, timeoutMs: 1000)]
    public function openBreakerAlwaysThrowsAndNeverInvokesCallback(int $cooldownSeconds): void
    {
        $cb = $this->breaker(cooldown: Duration::seconds($cooldownSeconds));
        $cb->forceOpen();
        $invoked = false;

        try {
            $cb->call(static function () use (&$invoked): string {
                $invoked = true;

                return 'ok';
            });
            Assert::true(actual: false);
        } catch (CircuitOpenException) {
            // expected
        }

        Assert::false($invoked);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function openBreakerAlwaysThrowsAndNeverInvokesCallbackGenerators(): array
    {
        return ['cooldownSeconds' => Gen::intBetween(1, 3600)];
    }

    /**
     * @param (callable(\Throwable): bool)|null $isFailure
     * @param (callable(mixed): Outcome)|null $classifyResult
     * @param (callable(\Throwable, CircuitTransition): void)|null $observerErrorHandler
     */
    private function breaker(
        int $failures = 5,
        int $window = 10,
        ?Duration $cooldown = null,
        int $successThreshold = 1,
        int $probeLimit = 1,
        ?Duration $probeTimeout = null,
        ?callable $isFailure = null,
        ?callable $classifyResult = null,
        ?CircuitObserver $observer = null,
        ?callable $observerErrorHandler = null,
    ): CircuitBreaker {
        return new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(failures: $failures, window: $window, within: Duration::seconds(60)),
                cooldown: $cooldown ?? Duration::seconds(30),
                successThreshold: $successThreshold,
                isFailure: $isFailure ?? static fn(\Throwable $e): bool => true,
                probeLimit: $probeLimit,
                probeTimeout: $probeTimeout,
                classifyResult: $classifyResult,
            ),
            storage: $this->storage,
            clock: $this->clock,
            observer: $observer,
            observerErrorHandler: $observerErrorHandler,
        );
    }

    /** @return list<CircuitTransition> */
    private function transitionsReceivedBy(CircuitObserver $observer): array
    {
        return array_map(
            static fn(Invocation $call): CircuitTransition => $call->args[0],
            Understudy::calls(fn() => $observer->onTransition(Arg::any())),
        );
    }

    /** @return list<string> */
    private function admitAttemptIds(Storage $storage): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->args[3],
            Understudy::calls(fn() => $storage->admit(Arg::any(), Arg::any(), Arg::any(), Arg::any())),
        );
    }

    /** @return list<string> */
    private function recordedAttemptIds(Storage $storage): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->args[6],
            Understudy::calls(fn() => $storage->recordOutcome(
                Arg::any(),
                Arg::any(),
                Arg::any(),
                Arg::any(),
                Arg::any(),
                Arg::any(),
                Arg::any(),
            )),
        );
    }

    private function callAndSwallow(CircuitBreaker $cb): void
    {
        try {
            $cb->call(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected - assertions inspect breaker state afterwards.
        }
    }

    private function configForProbeTimeout(Duration $probeTimeout): BreakerConfig
    {
        return new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 2,
            isFailure: static fn(\Throwable $e): bool => true,
            probeTimeout: $probeTimeout,
        );
    }
}
