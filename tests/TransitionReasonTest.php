<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\TransitionReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TransitionReason::class)]
final class TransitionReasonTest
{
    public function exposesStableValues(): void
    {
        Assert::same(TransitionReason::FailureThresholdReached->value, 'failure-threshold-reached');
        Assert::same(TransitionReason::from('probe-failed'), TransitionReason::ProbeFailed);
    }
}
