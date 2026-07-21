<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\AdmissionResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AdmissionResult::class)]
final class AdmissionResultTest
{
    public function exposesDecisionAndOptionalTransition(): void
    {
        $result = new AdmissionResult(Admission::Allowed);

        Assert::same($result->admission(), Admission::Allowed);
        Assert::null($result->transition());
    }
}
