<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Metrics;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * {@see Metrics::fromStateRecord()} is a projection, and a projection that
 * swaps two same-typed fields passes every example where they happen to be
 * equal — which, for counters, is most of the ones anyone writes by hand.
 *
 * The records are generated with {@see Gen::forClass()}: the constructor
 * already declares what a record is (a state, three counters, a timestamp),
 * and repeating that in a generator here would be the same information written
 * twice, drifting the day someone adds a field.
 */
#[Test]
#[Covers(Metrics::class)]
final class MetricsProjectionPropertyTest
{
    #[Property(runs: 200)]
    public function everyFieldOfTheRecordSurvivesTheProjection(StateRecord $record): void
    {
        $metrics = Metrics::fromStateRecord($record);

        Assert::same($metrics->state(), $record->state());
        Assert::same($metrics->successes(), $record->successes());
        Assert::same($metrics->failures(), $record->failures());
        Assert::same($metrics->rejected(), $record->rejected());
        Assert::same($metrics->openedAt()->getTimestamp(), $record->openedAt()->getTimestamp());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyFieldOfTheRecordSurvivesTheProjectionGenerators(): array
    {
        return ['record' => Gen::forClass(StateRecord::class)];
    }
}
