<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

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
}
