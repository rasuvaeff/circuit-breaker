<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * One step against a breaker's storage, with the invariant it is responsible
 * for.
 *
 * The postconditions are deliberately the ones that hold without knowing how
 * the window is counted: a breaker that has never seen a failure is closed,
 * and a probe is never admitted before the clock has moved past a cooldown.
 * Asserting a reimplementation of the counting would only assert the
 * reimplementation.
 */
final readonly class BreakerCommand implements Command
{
    public function __construct(
        private BreakerAction $action,
        private int $advanceSeconds = 0,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): BreakerModel
    {
        \assert($model instanceof BreakerModel);

        return match ($this->action) {
            BreakerAction::RecordFailure => $model->withFailure(),
            BreakerAction::Advance => $model->withTimeAdvancedBy(
                new \DateInterval(sprintf('PT%dS', $this->advanceSeconds)),
                // The config below uses a 30-second cooldown; anything shorter
                // cannot make a probe due on its own.
                $this->advanceSeconds >= 30,
            ),
            default => $model,
        };
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($model instanceof BreakerModel);
        \assert($system instanceof BreakerHarness);

        $attempt = uniqid('attempt-', more_entropy: true);

        return match ($this->action) {
            BreakerAction::Advance => $this->advance($system),
            BreakerAction::Admit => $system->storage
                ->admit(BreakerHarness::KEY, $system->config, $system->now, $attempt)
                ->admission(),
            BreakerAction::RecordSuccess, BreakerAction::RecordFailure => $system->storage->recordOutcome(
                BreakerHarness::KEY,
                $this->action === BreakerAction::RecordSuccess ? Outcome::Success : Outcome::Failure,
                $system->config,
                $system->now,
                Admission::Allowed,
                $system->now,
                $attempt,
            )->state(),
        };
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof BreakerModel);

        // A breaker that has never been told about a failure has nothing to
        // open on, whatever else the sequence did.
        //
        // This is the only per-command invariant here, and the shrinker is why:
        // the first version of this method also claimed that admission cannot
        // be granted before a cooldown, and was handed [RecordFailure, Admit]
        // as a two-step counterexample. One failure out of a threshold of three
        // leaves the breaker CLOSED, where admission is granted for the
        // ordinary reason. The cooldown invariant is a fact about the whole
        // sequence rather than about one step, so it lives in the property.
        if (!$model->anyFailureRecorded && $this->action !== BreakerAction::RecordFailure) {
            return $result instanceof CircuitState ? $result === CircuitState::Closed : true;
        }

        return true;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->action === BreakerAction::Advance
            ? sprintf('Advance(%ds)', $this->advanceSeconds)
            : $this->action->name;
    }

    private function advance(BreakerHarness $system): \DateTimeImmutable
    {
        $system->now = $system->now->add(new \DateInterval(sprintf('PT%dS', $this->advanceSeconds)));

        return $system->now;
    }
}
