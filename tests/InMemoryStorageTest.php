<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Internal\BreakerState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\CircuitBreaker\TransitionReason;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryStorage::class)]
// InMemoryStorage delegates every transition to the pure functions in
// Internal\BreakerState (see AGENTS.md golden rule 3) - this test class is
// the sole exerciser of that shared logic in the Unit suite (ApcuStorage
// reuses the same functions but only through the Redis/APCu-gated
// Integration suite). Admission/CircuitState/Outcome are the enums
// BreakerState's transitions are expressed in terms of, and every case of
// each is asserted somewhere in this file - see ER-003 in docs/evolved-rules.md
// for why testo/infection require explicit #[Covers] naming even when a
// class is already exercised.
#[Covers(BreakerState::class)]
#[Covers(Admission::class)]
#[Covers(CircuitState::class)]
#[Covers(Outcome::class)]
final class InMemoryStorageTest
{
    use StorageCalls;

    private InMemoryStorage $storage;
    private \DateTimeImmutable $base;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->base = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }

    public function admitAllowsInClosed(): void
    {
        $admission = $this->admitOn($this->storage, 'svc', $this->config(), $this->base)->admission();

        Assert::same($admission, Admission::Allowed);
    }

    public function closedTransitionsToOpenAfterFailureThreshold(): void
    {
        $config = $this->config(failures: 2, window: 5, within: Duration::seconds(60));

        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $first = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        Assert::same($first->state(), CircuitState::Open);
        Assert::same($first->failures(), 2);
    }

    public function closedStaysClosedBelowFailureThreshold(): void
    {
        $config = $this->config(failures: 3, window: 5, within: Duration::seconds(60));

        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $record = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->failures(), 2);
    }

    public function successesDiluteTheWindowWithoutCountingAsFailures(): void
    {
        $config = $this->config(failures: 2, window: 5, within: Duration::seconds(60));

        $this->recordOn($this->storage, 'svc', Outcome::Success, $config, $this->base)->state();
        $record = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->successes(), 1);
        Assert::same($record->failures(), 1);
    }

    public function ignoredOutcomeDoesNotEnterTheWindow(): void
    {
        $config = $this->config(failures: 1, window: 5, within: Duration::seconds(60));

        $record = $this->recordOn($this->storage, 'svc', Outcome::Ignored, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->successes(), 0);
        Assert::same($record->failures(), 0);
    }

    public function ringBufferIsCappedByWindow(): void
    {
        $config = $this->config(failures: 3, window: 3, within: Duration::seconds(60));

        for ($i = 0; $i < 5; ++$i) {
            $this->recordOn($this->storage, 'svc', Outcome::Success, $config, $this->base)->state();
        }
        $record = $this->storage->snapshot('svc');

        Assert::same($record->successes(), 3);
    }

    public function entriesOlderThanWithinAreEvicted(): void
    {
        $config = $this->config(failures: 2, window: 10, within: Duration::seconds(5));

        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $later = $this->base->modify('+10 seconds');
        $record = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $later)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->failures(), 1);
    }

    /**
     * `within` bounds the window by "any call older than `within` has already
     * aged out" (see AGENTS.md) - an entry timestamped exactly at the cutoff
     * has NOT aged out yet, it ages out the instant it crosses the boundary.
     */
    public function ringEntryExactlyAtTheWithinCutoffIsStillCounted(): void
    {
        $config = $this->config(failures: 2, window: 10, within: Duration::seconds(5));

        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $boundary = $this->base->modify('+5 seconds');
        $record = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $boundary)->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->failures(), 2);
    }

    public function recordOutcomeReportsTransitionWhenFailureThresholdOpensTheCircuit(): void
    {
        $config = $this->config(failures: 1, window: 1);

        $result = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base);

        $transition = $result->transition();
        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->from(), CircuitState::Closed);
        Assert::same($transition->to(), CircuitState::Open);
        Assert::same($transition->reason(), TransitionReason::FailureThresholdReached);
    }

    public function admitRejectsInOpenBeforeCooldown(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        $admission = $this->admitOn($this->storage, 'svc', $config, $this->base->modify('+10 seconds'))->admission();

        Assert::same($admission, Admission::Rejected);
        Assert::same($this->storage->snapshot('svc')->rejected(), 1);
    }

    public function admitTransitionsToHalfOpenAfterCooldown(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        $admission = $this->admitOn($this->storage, 'svc', $config, $this->base->modify('+31 seconds'))->admission();

        Assert::same($admission, Admission::Probe);
        Assert::same($this->storage->snapshot('svc')->state(), CircuitState::HalfOpen);
    }

    public function admitRejectsBeyondProbeLimit(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 2);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');

        $first = $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();
        $second = $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();
        $third = $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();

        Assert::same($first, Admission::Probe);
        Assert::same($second, Admission::Probe);
        Assert::same($third, Admission::Rejected);
        Assert::same($this->storage->snapshot('svc')->rejected(), 1);
    }

    /**
     * Releasing one occupied probe slot must free exactly one slot, no more
     * and no less - two probes admitted, one released, must allow exactly one
     * fresh probe before rejecting again.
     */
    public function releasingOneProbeSlotAllowsExactlyOneFreshProbe(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 2);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Rejected);

        $this->recordOn($this->storage, 'svc', Outcome::Ignored, $config, $probeTime, Admission::Probe)->state();

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Rejected);
    }

    /**
     * `recordOutcome()` MUST be called exactly once per admitted probe
     * (AGENTS.md golden rule 3) - but the slot-release arithmetic clamps at 0
     * defensively. A caller bug that releases the same probe twice must not
     * underflow `probeSlots` and leak an extra unit of admission capacity.
     */
    public function doubleReleasingAProbeSlotNeverLeavesItNegative(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 1);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);

        $this->recordOn($this->storage, 'svc', Outcome::Ignored, $config, $probeTime, Admission::Probe, $probeTime);
        $this->recordOn($this->storage, 'svc', Outcome::Ignored, $config, $probeTime, Admission::Probe, $probeTime);

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Rejected);
    }

    public function abandonedProbeSlotIsReclaimedAfterTimeout(): void
    {
        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState('svc', CircuitState::Open, $this->base);
        $probeTime = $this->base->modify('+30 seconds');

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same(
            $this->admitOn($this->storage, 'svc', $config, $probeTime->modify('+4 seconds'))->admission(),
            Admission::Rejected,
        );
        Assert::same(
            $this->admitOn($this->storage, 'svc', $config, $probeTime->modify('+5 seconds'))->admission(),
            Admission::Probe,
        );
        // The reclaim above must leave exactly one slot occupied (probeLimit
        // is 1 by default) - a second admit at the same instant, with no
        // further reclaim possible, must be rejected.
        Assert::same(
            $this->admitOn($this->storage, 'svc', $config, $probeTime->modify('+5 seconds'))->admission(),
            Admission::Rejected,
        );
    }

    public function freshProbeAfterIdleHalfOpenDoesNotInheritExpiredGeneration(): void
    {
        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(1),
            successThreshold: 3,
            probeLimit: 1,
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState('svc', CircuitState::Open, $this->base);
        $firstProbeAt = $this->base->modify('+1 second');
        $this->admitOn($this->storage, 'svc', $config, $firstProbeAt)->admission();
        $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Success,
            $config,
            $firstProbeAt,
            Admission::Probe,
            $firstProbeAt,
        )->state();
        $later = $firstProbeAt->modify('+10 seconds');

        $first = $this->admitOn($this->storage, 'svc', $config, $later)->admission();
        $second = $this->admitOn($this->storage, 'svc', $config, $later)->admission();

        Assert::same($first, Admission::Probe);
        Assert::same($second, Admission::Rejected);
    }

    public function outcomeFromExpiredProbeGenerationIsIgnored(): void
    {
        $config = $this->config(
            failures: 1,
            window: 1,
            cooldown: Duration::seconds(30),
            probeTimeout: Duration::seconds(5),
        );
        $this->storage->forceState('svc', CircuitState::Open, $this->base);
        $firstProbeAt = $this->base->modify('+30 seconds');
        $secondProbeAt = $firstProbeAt->modify('+5 seconds');
        $this->admitOn($this->storage, 'svc', $config, $firstProbeAt)->admission();
        $this->admitOn($this->storage, 'svc', $config, $secondProbeAt)->admission();

        $stale = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Failure,
            $config,
            $secondProbeAt,
            Admission::Probe,
            $firstProbeAt,
        )->state();
        $closed = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Success,
            $config,
            $secondProbeAt,
            Admission::Probe,
            $secondProbeAt,
        )->state();

        Assert::same($stale->state(), CircuitState::HalfOpen);
        Assert::same($closed->state(), CircuitState::Closed);
    }

    public function halfOpenClosesAfterSuccessThreshold(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 2);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();

        $first = $this->recordOn($this->storage, 'svc', Outcome::Success, $config, $probeTime, Admission::Probe)->state();
        Assert::same($first->state(), CircuitState::HalfOpen);
        Assert::same($first->successes(), 1);
        Assert::same($first->failures(), 0);

        $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();
        $secondResult = $this->recordOn($this->storage, 'svc', Outcome::Success, $config, $probeTime, Admission::Probe);
        $second = $secondResult->state();

        Assert::same($second->state(), CircuitState::Closed);
        Assert::same($second->rejected(), 0);

        $transition = $secondResult->transition();
        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->reason(), TransitionReason::ProbeSucceeded);
    }

    public function lateProbeFailureReopensAfterSiblingProbeClosedCircuit(): void
    {
        $config = $this->config(failures: 5, window: 10, cooldown: Duration::seconds(30), probeLimit: 2);
        $this->storage->forceState('svc', CircuitState::Open, $this->base);
        $probeTime = $this->base->modify('+30 seconds');

        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);

        $closed = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Probe,
        )->state();
        $reopened = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Failure,
            $config,
            $probeTime,
            Admission::Probe,
        )->state();

        Assert::same($closed->state(), CircuitState::Closed);
        Assert::same($reopened->state(), CircuitState::Open);
        Assert::same($reopened->failures(), 0);
        Assert::same($reopened->rejected(), 0);
    }

    /**
     * A call admitted while `Closed` finishes after the breaker has opened and
     * a fresh `HalfOpen` generation has started. It is not a probe of that
     * generation: its success must not count towards `successThreshold`, and
     * its failure must not reopen the circuit.
     */
    public function lateAllowedOutcomeIsNotCountedAsHalfOpenProbe(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: 1);
        $admittedAt = $this->base;
        Assert::same($this->admitOn($this->storage, 'svc', $config, $admittedAt)->admission(), Admission::Allowed);

        // A sibling call opens the circuit, the cooldown elapses, a new probe
        // generation starts - all while the Allowed call is still running.
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Probe);

        $lateSuccess = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Success,
            $config,
            $probeTime,
            Admission::Allowed,
            $admittedAt,
        )->state();

        Assert::same($lateSuccess->state(), CircuitState::HalfOpen);
        Assert::same($lateSuccess->successes(), 0);
    }

    public function lateAllowedFailureDoesNotReopenFromHalfOpen(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $admittedAt = $this->base;
        $this->admitOn($this->storage, 'svc', $config, $admittedAt)->admission();
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();

        $lateFailure = $this->recordOn(
            $this->storage,
            'svc',
            Outcome::Failure,
            $config,
            $probeTime,
            Admission::Allowed,
            $admittedAt,
        )->state();

        Assert::same($lateFailure->state(), CircuitState::HalfOpen);

        // The probe slot the real probe holds is untouched by the late call.
        Assert::same($this->admitOn($this->storage, 'svc', $config, $probeTime)->admission(), Admission::Rejected);
    }

    public function halfOpenReopensOnFailureAndKeepsAccumulatingRejected(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        // rejected once while still Open.
        $this->admitOn($this->storage, 'svc', $config, $this->base->modify('+1 second'))->admission();

        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();
        $result = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $probeTime, Admission::Probe);
        $record = $result->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->rejected(), 1);

        $transition = $result->transition();
        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->reason(), TransitionReason::ProbeFailed);
    }

    public function halfOpenIgnoredOutcomeReleasesSlotWithoutTransition(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), probeLimit: 1);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();

        $record = $this->recordOn($this->storage, 'svc', Outcome::Ignored, $config, $probeTime, Admission::Probe)->state();
        Assert::same($record->state(), CircuitState::HalfOpen);
        Assert::same($record->successes(), 0);

        // Slot released - a fresh probe must be admitted again.
        $admission = $this->admitOn($this->storage, 'svc', $config, $probeTime)->admission();
        Assert::same($admission, Admission::Probe);
    }

    public function recordOutcomeIsNoOpWhileOpen(): void
    {
        $config = $this->config();
        $this->storage->forceState('svc', CircuitState::Open, $this->base);

        $record = $this->recordOn($this->storage, 'svc', Outcome::Success, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->successes(), 0);
        Assert::same($record->failures(), 0);
    }

    public function forceStateResetsCounters(): void
    {
        $config = $this->config(failures: 1, window: 1);
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        Assert::same($this->storage->snapshot('svc')->state(), CircuitState::Open);

        $this->storage->forceState('svc', CircuitState::Closed, $this->base);
        $record = $this->storage->snapshot('svc');

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->failures(), 0);
        Assert::same($record->rejected(), 0);
    }

    /**
     * `forceState()` builds its entry from `BreakerState::fresh()` and then
     * overrides `state` directly - unlike a natural `Open -> HalfOpen`
     * transition (which `admit()` always resets explicitly), forcing straight
     * to `HalfOpen` is the one path that exposes `fresh()`'s own
     * `probeSuccesses`/`probeSlots` values unfiltered.
     */
    public function forceStateToHalfOpenStartsWithNoOccupiedProbeSlotsOrSuccesses(): void
    {
        $config = $this->config(probeLimit: 1);
        $this->storage->forceState('svc', CircuitState::HalfOpen, $this->base);

        $record = $this->storage->snapshot('svc');
        Assert::same($record->successes(), 0);
        Assert::same($record->failures(), 0);

        Assert::same($this->admitOn($this->storage, 'svc', $config, $this->base)->admission(), Admission::Probe);
        Assert::same($this->admitOn($this->storage, 'svc', $config, $this->base)->admission(), Admission::Rejected);
    }

    public function forceStateReportsAFullyPopulatedTransition(): void
    {
        $transition = $this->storage->forceState('svc', CircuitState::Open, $this->base);

        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->breakerName(), 'svc');
        Assert::same($transition->from(), CircuitState::Closed);
        Assert::same($transition->to(), CircuitState::Open);
        Assert::same($transition->reason(), TransitionReason::ForcedOpen);
        Assert::same($transition->occurredAt(), $this->base);
        Assert::same($transition->state()->state(), CircuitState::Open);
    }

    #[DataProvider('forcedReasonProvider')]
    public function forceStateReportsTheReasonMatchingTheTargetState(
        CircuitState $target,
        TransitionReason $reason,
    ): void {
        // Start somewhere else so the transition is never a no-op.
        $this->storage->forceState('svc', $target === CircuitState::Open
            ? CircuitState::Closed
            : CircuitState::Open, $this->base);

        $transition = $this->storage->forceState('svc', $target, $this->base);

        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->reason(), $reason);
    }

    public static function forcedReasonProvider(): iterable
    {
        yield 'open' => [CircuitState::Open, TransitionReason::ForcedOpen];
        yield 'closed' => [CircuitState::Closed, TransitionReason::ForcedClosed];
        yield 'half-open' => [CircuitState::HalfOpen, TransitionReason::ForcedHalfOpen];
    }

    /**
     * Forcing the state that is already in effect resets the counters but is
     * not a state change — no transition may be reported for it.
     */
    public function forcingTheCurrentStateReportsNoTransition(): void
    {
        $this->storage->forceState('svc', CircuitState::Open, $this->base);

        Assert::null($this->storage->forceState('svc', CircuitState::Open, $this->base));
    }

    public function outcomeWithoutAStateChangeReportsNoTransition(): void
    {
        $config = $this->config(failures: 5, window: 10);

        $result = $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base);

        Assert::same($result->state()->state(), CircuitState::Closed);
        Assert::null($result->transition());
    }

    public function snapshotHasNoSideEffects(): void
    {
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30));
        $this->recordOn($this->storage, 'svc', Outcome::Failure, $config, $this->base)->state();

        $this->storage->snapshot('svc');
        $this->storage->snapshot('svc');
        $record = $this->storage->snapshot('svc');

        Assert::same($record->state(), CircuitState::Open);
    }

    public function keysAreIsolated(): void
    {
        $config = $this->config(failures: 1, window: 1);
        $this->recordOn($this->storage, 'a', Outcome::Failure, $config, $this->base)->state();

        Assert::same($this->storage->snapshot('a')->state(), CircuitState::Open);
        Assert::same($this->storage->snapshot('b')->state(), CircuitState::Closed);
    }

    /**
     * `stateMonotoneWithoutTrigger`: `admit()` alone, however many times, never
     * moves a `Closed` breaker — only `recordOutcome()` (a real outcome) can.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function admitAloneNeverChangesClosedState(int $callCount): void
    {
        $storage = new InMemoryStorage();
        $config = $this->config();

        for ($i = 0; $i < $callCount; ++$i) {
            $this->admitOn($storage, 'svc', $config, $this->base)->admission();
        }

        Assert::same($storage->snapshot('svc')->state(), CircuitState::Closed);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function admitAloneNeverChangesClosedStateGenerators(): array
    {
        return ['callCount' => Gen::intBetween(0, 100)];
    }

    /**
     * `failuresConservationInClosed`: recording only successes never grows the
     * failure count.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function failuresNeverGrowFromSuccessesAlone(int $successCount): void
    {
        $storage = new InMemoryStorage();
        $config = $this->config(failures: 5, window: 200, within: Duration::seconds(3600));

        for ($i = 0; $i < $successCount; ++$i) {
            $this->recordOn($storage, 'svc', Outcome::Success, $config, $this->base)->state();
        }

        Assert::same($storage->snapshot('svc')->failures(), 0);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function failuresNeverGrowFromSuccessesAloneGenerators(): array
    {
        return ['successCount' => Gen::intBetween(0, 50)];
    }

    /**
     * `openOnlyAfterThreshold`: fewer failures than `failureThreshold` within
     * the window never opens the breaker.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function neverOpensBeforeFailureThresholdIsReached(int $failureThreshold): void
    {
        $belowThreshold = Gen::draw(Gen::intBetween(0, $failureThreshold - 1));

        $storage = new InMemoryStorage();
        $config = $this->config(failures: $failureThreshold, window: $failureThreshold, within: Duration::seconds(3600));

        $record = null;
        for ($i = 0; $i < $belowThreshold; ++$i) {
            $record = $this->recordOn($storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        }

        Assert::same($storage->snapshot('svc')->state(), CircuitState::Closed);
        if ($record !== null) {
            Assert::same($record->state(), CircuitState::Closed);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function neverOpensBeforeFailureThresholdIsReachedGenerators(): array
    {
        return ['failureThreshold' => Gen::intBetween(1, 30)];
    }

    /**
     * `halfOpenOnlyAfterCooldown`: `admit()` never grants `HalfOpen` before
     * `openedAt + cooldown` has elapsed.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function neverEntersHalfOpenBeforeCooldownElapses(int $cooldownSeconds): void
    {
        $elapsedSeconds = Gen::draw(Gen::intBetween(0, $cooldownSeconds - 1));

        $storage = new InMemoryStorage();
        $config = $this->config(cooldown: Duration::seconds($cooldownSeconds));
        $storage->forceState('svc', CircuitState::Open, $this->base);

        $admission = $this->admitOn($storage, 'svc', $config, $this->base->modify("+{$elapsedSeconds} seconds"))->admission();

        Assert::same($admission, Admission::Rejected);
        Assert::same($storage->snapshot('svc')->state(), CircuitState::Open);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function neverEntersHalfOpenBeforeCooldownElapsesGenerators(): array
    {
        return ['cooldownSeconds' => Gen::intBetween(1, 3600)];
    }

    /**
     * `halfOpenToClosedNeedsN`: `HalfOpen` stays `HalfOpen` for every success
     * before `successThreshold`, and only closes on the Nth.
     */
    #[Property(runs: 150, timeoutMs: 1000)]
    public function halfOpenClosesOnlyAfterSuccessThresholdConsecutiveSuccesses(int $successThreshold): void
    {
        $storage = new InMemoryStorage();
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: $successThreshold);
        $this->recordOn($storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');

        for ($i = 1; $i < $successThreshold; ++$i) {
            $this->admitOn($storage, 'svc', $config, $probeTime)->admission();
            $record = $this->recordOn($storage, 'svc', Outcome::Success, $config, $probeTime, Admission::Probe)->state();
            Assert::same($record->state(), CircuitState::HalfOpen);
        }

        $this->admitOn($storage, 'svc', $config, $probeTime)->admission();
        $final = $this->recordOn($storage, 'svc', Outcome::Success, $config, $probeTime, Admission::Probe)->state();

        Assert::same($final->state(), CircuitState::Closed);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function halfOpenClosesOnlyAfterSuccessThresholdConsecutiveSuccessesGenerators(): array
    {
        return ['successThreshold' => Gen::intBetween(1, 15)];
    }

    /**
     * `halfOpenToOpenOnAnyFailure`: any failure while `HalfOpen` transitions
     * to `Open` immediately and restarts the cooldown clock at that failure's
     * timestamp.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function halfOpenFailureReopensAndRestartsCooldown(int $successThreshold): void
    {
        $storage = new InMemoryStorage();
        $config = $this->config(failures: 1, window: 1, cooldown: Duration::seconds(30), successThreshold: $successThreshold);
        $this->recordOn($storage, 'svc', Outcome::Failure, $config, $this->base)->state();
        $probeTime = $this->base->modify('+31 seconds');
        $this->admitOn($storage, 'svc', $config, $probeTime)->admission();

        $record = $this->recordOn($storage, 'svc', Outcome::Failure, $config, $probeTime, Admission::Probe)->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->openedAt()->getTimestamp(), $probeTime->getTimestamp());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function halfOpenFailureReopensAndRestartsCooldownGenerators(): array
    {
        return ['successThreshold' => Gen::intBetween(1, 15)];
    }

    /**
     * `rejectedCounterIsMonotone`: every rejected `admit()` in `Open` increments
     * `rejected` by exactly 1.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function rejectedCounterIncrementsOnceForEachRejectedAdmit(int $attempts): void
    {
        $storage = new InMemoryStorage();
        $config = $this->config(cooldown: Duration::seconds(3600));
        $storage->forceState('svc', CircuitState::Open, $this->base);

        for ($i = 0; $i < $attempts; ++$i) {
            Assert::same($this->admitOn($storage, 'svc', $config, $this->base)->admission(), Admission::Rejected);
        }

        Assert::same($storage->snapshot('svc')->rejected(), $attempts);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function rejectedCounterIncrementsOnceForEachRejectedAdmitGenerators(): array
    {
        return ['attempts' => Gen::intBetween(0, 50)];
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
            name: 'svc',
            failureThreshold: Ratio::of(failures: $failures, window: $window, within: $within ?? Duration::seconds(60)),
            cooldown: $cooldown ?? Duration::seconds(30),
            successThreshold: $successThreshold,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: $probeLimit,
            probeTimeout: $probeTimeout,
        );
    }
}
