<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * The public surface of a circuit breaker: depend on this type when a
 * decorator (logging, metrics, tenant-aware routing) or a test double must
 * be able to stand in for {@see CircuitBreaker}.
 *
 * @api
 */
interface CircuitBreakerInterface
{
    /**
     * @template T
     *
     * @param callable(): T                  $callback
     * @param (callable(\Throwable): T)|null $fallback
     *
     * @return T
     *
     * @throws CircuitOpenException when rejected and no `$fallback` is given
     * @throws StorageFailure       when the storage backend fails
     */
    public function call(callable $callback, ?callable $fallback = null): mixed;

    public function canCall(): bool;

    public function state(): CircuitState;

    public function metrics(): Metrics;

    public function forceOpen(): void;

    public function forceClosed(): void;
}
