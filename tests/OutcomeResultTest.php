<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\OutcomeResult;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OutcomeResult::class)]
final class OutcomeResultTest
{
    public function exposesStateAndOptionalTransition(): void
    {
        $state = new StateRecord(CircuitState::Closed, new \DateTimeImmutable('@0'), 0, 0, 0);
        $result = new OutcomeResult($state);

        Assert::same($result->state(), $state);
        Assert::null($result->transition());
    }
}
