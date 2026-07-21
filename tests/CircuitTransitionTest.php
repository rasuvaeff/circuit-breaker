<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\CircuitBreaker\TransitionReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CircuitTransition::class)]
final class CircuitTransitionTest
{
    public function exposesTransitionMetadata(): void
    {
        $at = new \DateTimeImmutable('@0');
        $state = new StateRecord(CircuitState::Open, $at, 0, 0, 0);
        $transition = new CircuitTransition('payments', CircuitState::Closed, CircuitState::Open, $at, TransitionReason::FailureThresholdReached, $state);

        Assert::same($transition->breakerName(), 'payments');
        Assert::same($transition->from(), CircuitState::Closed);
        Assert::same($transition->to(), CircuitState::Open);
        Assert::same($transition->occurredAt(), $at);
        Assert::same($transition->reason(), TransitionReason::FailureThresholdReached);
        Assert::same($transition->state(), $state);
    }
}
