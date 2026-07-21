<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Ratio::class)]
final class RatioTest
{
    public function exposesConstructorValues(): void
    {
        $ratio = Ratio::of(failures: 5, window: 10, within: Duration::seconds(60));

        Assert::same($ratio->failures(), 5);
        Assert::same($ratio->window(), 10);
        Assert::true($ratio->within()->equals(Duration::seconds(60)));
    }

    public function windowMayEqualFailures(): void
    {
        $ratio = Ratio::of(failures: 3, window: 3, within: Duration::seconds(1));

        Assert::same($ratio->failures(), 3);
        Assert::same($ratio->window(), 3);
    }

    public function rejectsFailuresBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Failures');

        Ratio::of(failures: 0, window: 5, within: Duration::seconds(1));
    }

    public function rejectsWindowBelowFailures(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Window');

        Ratio::of(failures: 5, window: 4, within: Duration::seconds(1));
    }

    public function rejectsZeroWithin(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Within');

        Ratio::of(failures: 1, window: 1, within: Duration::zero());
    }
}
