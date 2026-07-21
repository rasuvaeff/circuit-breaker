<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StateRecord::class)]
final class StateRecordTest
{
    public function exposesConstructorValues(): void
    {
        $openedAt = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $record = new StateRecord(
            state: CircuitState::HalfOpen,
            openedAt: $openedAt,
            successes: 2,
            failures: 1,
            rejected: 3,
        );

        Assert::same($record->state(), CircuitState::HalfOpen);
        Assert::same($record->openedAt(), $openedAt);
        Assert::same($record->successes(), 2);
        Assert::same($record->failures(), 1);
        Assert::same($record->rejected(), 3);
    }
}
