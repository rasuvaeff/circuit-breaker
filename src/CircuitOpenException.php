<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Duration\Duration;

/**
 * Thrown by {@see CircuitBreaker::call()} when the breaker rejects a call
 * (`Open`, or `HalfOpen` with no free probe slot) and no `fallback` was
 * given — or passed to `fallback` itself, since it is a `\Throwable`.
 *
 * @api
 */
final class CircuitOpenException extends \RuntimeException
{
    public function __construct(
        public readonly string $breakerName,
        public readonly \DateTimeImmutable $retryAfter,
    ) {
        parent::__construct(sprintf(
            'Circuit "%s" is open, retry after %s',
            $breakerName,
            $retryAfter->format(\DateTimeInterface::ATOM),
        ));
    }

    /**
     * Time left until {@see $retryAfter}, relative to `$clock`: what a
     * `Retry-After` header or a re-queue delay needs. Never negative —
     * `Duration::zero()` once the instant has passed. Microsecond precision.
     */
    public function retryAfterIn(ClockInterface $clock): Duration
    {
        $now = $clock->now();
        $micros = ($this->retryAfter->getTimestamp() - $now->getTimestamp()) * 1_000_000
            + (int) $this->retryAfter->format('u') - (int) $now->format('u');

        return Duration::micros(max(0, $micros));
    }
}
