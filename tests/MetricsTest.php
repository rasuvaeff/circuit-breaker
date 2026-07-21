<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Metrics;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Metrics::class)]
final class MetricsTest
{
    public function exposesConstructorValues(): void
    {
        $openedAt = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $metrics = new Metrics(
            state: CircuitState::Open,
            successes: 4,
            failures: 5,
            rejected: 6,
            openedAt: $openedAt,
        );

        Assert::same($metrics->state(), CircuitState::Open);
        Assert::same($metrics->successes(), 4);
        Assert::same($metrics->failures(), 5);
        Assert::same($metrics->rejected(), 6);
        Assert::same($metrics->openedAt(), $openedAt);
    }

    public function fromStateRecordMirrorsEveryField(): void
    {
        $openedAt = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $record = new StateRecord(
            state: CircuitState::Closed,
            openedAt: $openedAt,
            successes: 1,
            failures: 2,
            rejected: 0,
        );

        $metrics = Metrics::fromStateRecord($record);

        Assert::same($metrics->state(), CircuitState::Closed);
        Assert::same($metrics->successes(), 1);
        Assert::same($metrics->failures(), 2);
        Assert::same($metrics->rejected(), 0);
        Assert::same($metrics->openedAt(), $openedAt);
    }
}
