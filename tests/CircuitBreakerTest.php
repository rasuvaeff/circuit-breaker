<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\AdmissionResult;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitObserver;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\OutcomeResult;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\CircuitBreaker\Storage;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\CircuitBreaker\StorageOperation;
use Rasuvaeff\CircuitBreaker\Tests\Support\RecordingObserver;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

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
        $observer = new class implements CircuitObserver {
            /** @var list<CircuitTransition> */
            public array $events = [];

            #[\Override]
            public function onTransition(CircuitTransition $transition): void
            {
                $this->events[] = $transition;
            }
        };
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        $this->callAndSwallow($cb);

        Assert::same(count($observer->events), 1);
        Assert::same($observer->events[0]->from(), CircuitState::Closed);
        Assert::same($observer->events[0]->to(), CircuitState::Open);
        Assert::same($observer->events[0]->reason()->value, 'failure-threshold-reached');
        Assert::same($observer->events[0]->state()->state(), $cb->state());
    }

    /**
     * `admit()` commits a transition of its own (`Open -> HalfOpen` once the
     * cooldown elapsed). `call()` must publish that one too, not only the
     * transitions that come out of `recordOutcome()`.
     */
    public function observerReceivesTheCooldownTransitionCommittedByAdmit(): void
    {
        $observer = new RecordingObserver();
        $cb = $this->breaker(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        $this->callAndSwallow($cb);
        $this->clock->advanceMs(31_000);
        $cb->call(static fn(): string => 'ok');

        Assert::same($observer->reasons(), [
            'failure-threshold-reached',
            'cooldown-elapsed',
            'probe-succeeded',
        ]);
        Assert::same($observer->events[1]->from(), CircuitState::Open);
        Assert::same($observer->events[1]->to(), CircuitState::HalfOpen);
    }

    public function forcedTransitionsAreReportedToTheObserver(): void
    {
        $observer = new RecordingObserver();
        $cb = $this->breaker(
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        $cb->forceOpen();
        $cb->forceClosed();

        Assert::same($observer->reasons(), ['forced-open', 'forced-closed']);
        Assert::same($observer->events[0]->to(), CircuitState::Open);
        Assert::same($observer->events[1]->from(), CircuitState::Open);
        Assert::same($observer->events[1]->to(), CircuitState::Closed);
    }

    public function forcingTheStateAlreadyInEffectReportsNothing(): void
    {
        $observer = new RecordingObserver();
        $cb = $this->breaker(
            observer: $observer,
            observerErrorHandler: static function (\Throwable $e, CircuitTransition $transition): void {},
        );

        $cb->forceClosed();

        Assert::same($observer->events, []);
    }

    public function storageFailureAfterSuccessfulCallbackIsNotRecordedAsDownstreamFailure(): void
    {
        $storage = new class implements Storage {
            public int $recordCalls = 0;

            /** @var list<string> */
            public array $admitAttemptIds = [];

            /** @var list<string> */
            public array $recordAttemptIds = [];

            #[\Override]
            public function admit(
                string $key,
                BreakerConfig $config,
                \DateTimeImmutable $now,
                string $attemptId,
            ): AdmissionResult {
                $this->admitAttemptIds[] = $attemptId;

                return new AdmissionResult(Admission::Allowed);
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
                ++$this->recordCalls;
                $this->recordAttemptIds[] = $attemptId;

                throw new \RuntimeException('storage unavailable');
            }

            #[\Override]
            public function snapshot(string $key): StateRecord
            {
                return new StateRecord(CircuitState::Closed, new \DateTimeImmutable('@0'), 0, 0, 0);
            }

            #[\Override]
            public function forceState(
                string $key,
                CircuitState $state,
                \DateTimeImmutable $now,
            ): ?CircuitTransition {
                return null;
            }
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
            $cb->call(static fn(): string => 'ok');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, StorageFailure::class);
        Assert::same($caught->operation, 'recordOutcome');
        Assert::instanceOf($caught->getPrevious(), \RuntimeException::class);
        Assert::same($caught->getPrevious()?->getMessage(), 'storage unavailable');
        Assert::same($storage->recordCalls, 1);
        Assert::same(preg_match('/^[a-f0-9]{32}:1$/', (string) $storage->admitAttemptIds[0]), 1);
        Assert::same($storage->recordAttemptIds[0], $storage->admitAttemptIds[0]);

        try {
            $cb->call(static fn(): string => 'ok');
        } catch (StorageFailure) {
            // expected
        }

        Assert::same(preg_match('/^[a-f0-9]{32}:2$/', (string) $storage->admitAttemptIds[1]), 1);
        Assert::same($storage->recordAttemptIds[1], $storage->admitAttemptIds[1]);
    }

    #[DataProvider('storageFailureCarriesTheOperationThatFailedProvider')]
    public function storageFailureCarriesTheOperationThatFailed(
        StorageOperation $throwingOperation,
        \Closure $trigger,
    ): void {
        $storage = new class ($throwingOperation) implements Storage {
            public function __construct(private readonly StorageOperation $throwingOperation) {}

            #[\Override]
            public function admit(
                string $key,
                BreakerConfig $config,
                \DateTimeImmutable $now,
                string $attemptId,
            ): AdmissionResult {
                if ($this->throwingOperation === StorageOperation::Admit) {
                    throw new \RuntimeException('storage unavailable');
                }

                return new AdmissionResult(Admission::Allowed);
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
                if ($this->throwingOperation === StorageOperation::RecordOutcome) {
                    throw new \RuntimeException('storage unavailable');
                }

                return new OutcomeResult(new StateRecord(CircuitState::Closed, new \DateTimeImmutable('@0'), 0, 0, 0));
            }

            #[\Override]
            public function snapshot(string $key): StateRecord
            {
                if ($this->throwingOperation === StorageOperation::Snapshot) {
                    throw new \RuntimeException('storage unavailable');
                }

                return new StateRecord(CircuitState::Closed, new \DateTimeImmutable('@0'), 0, 0, 0);
            }

            #[\Override]
            public function forceState(
                string $key,
                CircuitState $state,
                \DateTimeImmutable $now,
            ): ?CircuitTransition {
                if ($this->throwingOperation === StorageOperation::ForceState) {
                    throw new \RuntimeException('storage unavailable');
                }

                return null;
            }
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
            Assert::true(false);
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
    #[Property(runs: 100)]
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
            Assert::true(false);
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
