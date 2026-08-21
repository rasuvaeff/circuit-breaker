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
    /**
     * `$downstreamOutcome` carries the exception the protected callback threw
     * when the storage failed while recording that very outcome. A storage
     * failure outranks the downstream failure (it is an infrastructure
     * problem, not a downstream verdict), but the downstream exception must
     * stay reachable — the caller may still need to log or react to it.
     * `null` when the callback succeeded or had not run yet.
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $breakerName,
        \Throwable $previous,
        public readonly ?\Throwable $downstreamOutcome = null,
    ) {
        parent::__construct(
            sprintf('Circuit breaker storage failed during %s for "%s"', $operation, $breakerName),
            previous: $previous,
        );
    }
}
