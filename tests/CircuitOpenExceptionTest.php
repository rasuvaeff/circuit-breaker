<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

    #[DataProvider('retryAfterInProvider')]
    public function retryAfterInIsRelativeToTheClockAndNeverNegative(string $retryAfter, string $now, int $expectedMicros): void
    {
        $exception = new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable($retryAfter));

        $remaining = $exception->retryAfterIn(new FakeClock(new \DateTimeImmutable($now)));

        Assert::same($remaining->toMicros(), $expectedMicros);
    }

    public static function retryAfterInProvider(): iterable
    {
        yield 'whole seconds ahead' => ['2025-01-01T00:00:30+00:00', '2025-01-01T00:00:00+00:00', 30_000_000];
        yield 'sub-second precision kept' => ['2025-01-01T00:00:01.250000+00:00', '2025-01-01T00:00:00.000001+00:00', 1_249_999];
        yield 'borrow across the second boundary' => ['2025-01-01T00:00:01.100000+00:00', '2025-01-01T00:00:00.900000+00:00', 200_000];
        yield 'exactly now' => ['2025-01-01T00:00:00.500000+00:00', '2025-01-01T00:00:00.500000+00:00', 0];
        yield 'already passed' => ['2025-01-01T00:00:00+00:00', '2025-01-01T00:00:05+00:00', 0];
        yield 'passed by a microsecond' => ['2025-01-01T00:00:00.000000+00:00', '2025-01-01T00:00:00.000001+00:00', 0];
    }

    public function retryAfterInIgnoresTimezoneDifferences(): void
    {
        $exception = new CircuitOpenException(
            breakerName: 'svc',
            retryAfter: new \DateTimeImmutable('2025-01-01T03:00:10+03:00'),
        );

        $remaining = $exception->retryAfterIn(new FakeClock(new \DateTimeImmutable('2025-01-01T00:00:00+00:00')));

        Assert::true($remaining->equals(Duration::seconds(10)));
    }
}
