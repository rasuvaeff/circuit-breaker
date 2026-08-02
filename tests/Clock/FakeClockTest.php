<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Clock;

use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeClock::class)]
final class FakeClockTest
{
    public function defaultConstructorUsesTheDocumentedFixedEpoch(): void
    {
        $clock = new FakeClock();

        Assert::same(
            $clock->now()->format('Y-m-d\TH:i:s.uP'),
            '2025-01-01T00:00:00.000000+00:00',
        );
    }

    public function explicitConstructorArgumentIsReturnedVerbatim(): void
    {
        $now = new \DateTimeImmutable('2030-06-15T12:30:00.500000+00:00');
        $clock = new FakeClock($now);

        Assert::same($clock->now(), $now);
    }

    public function advanceMsMovesNowForward(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2025-01-01T00:00:00.000000+00:00'));

        $clock->advanceMs(1_500);

        Assert::same(
            $clock->now()->format('Y-m-d\TH:i:s.u'),
            '2025-01-01T00:00:01.500000',
        );
    }

    public function advanceMsByZeroLeavesNowUnchanged(): void
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00.000000+00:00');
        $clock = new FakeClock($now);

        $clock->advanceMs(0);

        Assert::same($clock->now()->format('Y-m-d\TH:i:s.u'), $now->format('Y-m-d\TH:i:s.u'));
    }

    public function advanceMsRejectsNegativeValues(): void
    {
        $clock = new FakeClock();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Milliseconds must be non-negative');

        $clock->advanceMs(-1);
    }
}
