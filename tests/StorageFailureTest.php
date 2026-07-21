<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\StorageFailure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StorageFailure::class)]
final class StorageFailureTest
{
    public function exposesOperationAndPreviousException(): void
    {
        $previous = new \RuntimeException('redis unavailable');
        $failure = new StorageFailure('admit', 'payments', $previous);

        Assert::same($failure->operation, 'admit');
        Assert::same($failure->breakerName, 'payments');
        Assert::same($failure->getPrevious(), $previous);
        Assert::string($failure->getMessage())->contains('admit');
        Assert::string($failure->getMessage())->contains('payments');
    }
}
