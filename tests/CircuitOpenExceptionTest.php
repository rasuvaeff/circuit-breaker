<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CircuitOpenException::class)]
final class CircuitOpenExceptionTest
{
    public function messageContainsBreakerNameAndRetryAfter(): void
    {
        $retryAfter = new \DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $exception = new CircuitOpenException(breakerName: 'stripe', retryAfter: $retryAfter);

        Assert::string($exception->getMessage())->contains('stripe');
        Assert::string($exception->getMessage())->contains($retryAfter->format(\DateTimeInterface::ATOM));
    }

    public function exposesBreakerNameAndRetryAfterAsPublicProperties(): void
    {
        $retryAfter = new \DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $exception = new CircuitOpenException(breakerName: 'stripe', retryAfter: $retryAfter);

        Assert::same($exception->breakerName, 'stripe');
        Assert::same($exception->retryAfter, $retryAfter);
    }

    public function isARuntimeException(): void
    {
        $exception = new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable());

        Assert::instanceOf($exception, \RuntimeException::class);
    }
}
