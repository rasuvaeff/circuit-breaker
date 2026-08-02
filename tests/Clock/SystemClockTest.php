<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Clock;

use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SystemClock::class)]
final class SystemClockTest
{
    public function nowReflectsTheRealWallClock(): void
    {
        $before = new \DateTimeImmutable();
        $now = (new SystemClock())->now();
        $after = new \DateTimeImmutable();

        Assert::true($now >= $before);
        Assert::true($now <= $after);
    }

    public function nowReturnsAFreshInstanceOnEveryCall(): void
    {
        $clock = new SystemClock();

        Assert::notSame($clock->now(), $clock->now());
    }
}
