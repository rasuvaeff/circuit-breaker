<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Wraps an exception thrown by a {@see Storage} operation so consumers can
 * distinguish breaker-infrastructure failures from downstream failures.
 *
 * @api
 */
final class StorageFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $breakerName,
        \Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Circuit breaker storage failed during %s for "%s"', $operation, $breakerName),
            previous: $previous,
        );
    }
}
