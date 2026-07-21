<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Psr\Clock\ClockInterface;

/**
 * Protects a downstream call behind a `Closed → Open → HalfOpen` state
 * machine backed by {@see Storage}.
 *
 * @api
 */
final class CircuitBreaker
{
    private readonly string $attemptPrefix;
    private readonly ?CircuitObserver $observer;

    /** @var (\Closure(\Throwable, CircuitTransition): void)|null */
    private readonly ?\Closure $observerErrorHandler;

    private int $attemptCounter = 0;

    /**
     * @param (callable(\Throwable, CircuitTransition): void)|null $observerErrorHandler
     */
    public function __construct(
        private readonly BreakerConfig $config,
        private readonly Storage $storage,
        private readonly ClockInterface $clock,
        ?CircuitObserver $observer = null,
        ?callable $observerErrorHandler = null,
    ) {
        if (($observer === null) !== ($observerErrorHandler === null)) {
            throw new \InvalidArgumentException('Observer and observer error handler must be configured together');
        }
        $this->attemptPrefix = bin2hex(random_bytes(16));
        $this->observer = $observer;
        $this->observerErrorHandler = $observerErrorHandler === null
            ? null
            : \Closure::fromCallable($observerErrorHandler);
    }

    /**
     * Invoke `$callback` under the breaker's protection.
     *
     * Flow: `admit()` decides whether the call may proceed. `Rejected` never
     * invokes `$callback` — it either calls `$fallback` with a fresh
     * {@see CircuitOpenException} or throws it. Otherwise `$callback` runs and
     * its outcome is classified via `$config->isFailure()` and recorded with
     * exactly one `recordOutcome()` call — including when `$callback` throws,
     * which is what releases a `HalfOpen` probe slot.
     *
     * @template T
     *
     * @param callable(): T             $callback
     * @param (callable(\Throwable): T)|null $fallback invoked when the call is
     *        rejected, or when `$callback` threw and `isFailure()` classified
     *        it as a failure. An `Ignored` exception (`isFailure() === false`)
     *        never triggers `$fallback` — it is always rethrown as-is.
     *
     * @return T
     *
     * @throws CircuitOpenException when rejected and no `$fallback` is given
     * @throws \Throwable the original exception, when `$callback` threw and
     *         either it was `Ignored` or it was a `Failure` with no `$fallback`
     */
    public function call(callable $callback, ?callable $fallback = null): mixed
    {
        $key = $this->config->name();
        $now = $this->clock->now();
        $attemptId = $this->nextAttemptId();
        $admissionResult = $this->storageOperation(
            StorageOperation::Admit,
            fn(): AdmissionResult => $this->storage->admit($key, $this->config, $now, $attemptId),
        );
        $this->observe($admissionResult->transition());
        $admission = $admissionResult->admission();

        if ($admission === Admission::Rejected) {
            $record = $this->storageOperation(
                StorageOperation::Snapshot,
                fn(): StateRecord => $this->storage->snapshot($key),
            );
            $retryAfter = $this->retryAfter($record);
            $exception = new CircuitOpenException(breakerName: $key, retryAfter: $retryAfter);

            if ($fallback !== null) {
                return $fallback($exception);
            }

            throw $exception;
        }

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            try {
                $isFailure = $this->config->isFailure($e);
            } catch (\Throwable $classifierError) {
                $this->recordOutcome($key, Outcome::Ignored, $admission, $now, $attemptId);

                throw $classifierError;
            }

            $this->recordOutcome(
                key: $key,
                outcome: $isFailure ? Outcome::Failure : Outcome::Ignored,
                admission: $admission,
                admittedAt: $now,
                attemptId: $attemptId,
            );

            if ($isFailure && $fallback !== null) {
                return $fallback($e);
            }

            throw $e;
        }

        try {
            $outcome = $this->config->classifyResult($result);
        } catch (\Throwable $classifierError) {
            $this->recordOutcome($key, Outcome::Ignored, $admission, $now, $attemptId);

            throw $classifierError;
        }

        $this->recordOutcome($key, $outcome, $admission, $now, $attemptId);

        return $result;
    }

    /**
     * Best-effort, side-effect-free check of whether a call would currently be
     * admitted. `Closed` and `HalfOpen` always report `true` — `HalfOpen`
     * probe-slot availability cannot be checked without occupying a slot, so
     * this is optimistic; `call()` may still reject it. `Open` reports `true`
     * only once the cooldown has elapsed.
     */
    public function canCall(): bool
    {
        $record = $this->storageOperation(
            StorageOperation::Snapshot,
            fn(): StateRecord => $this->storage->snapshot($this->config->name()),
        );

        return match ($record->state()) {
            CircuitState::Closed, CircuitState::HalfOpen => true,
            CircuitState::Open => $this->clock->now() >= $record->openedAt()->modify(
                sprintf('+%d microseconds', $this->config->cooldown()->toMicros()),
            ),
        };
    }

    /**
     * Current state, as of the last recorded outcome. Does not apply the lazy
     * `Open` → `HalfOpen` cooldown transition — that only happens inside
     * `call()`'s `admit()` step.
     */
    public function state(): CircuitState
    {
        return $this->storageOperation(
            StorageOperation::Snapshot,
            fn(): StateRecord => $this->storage->snapshot($this->config->name()),
        )->state();
    }

    public function metrics(): Metrics
    {
        return Metrics::fromStateRecord($this->storageOperation(
            StorageOperation::Snapshot,
            fn(): StateRecord => $this->storage->snapshot($this->config->name()),
        ));
    }

    /**
     * Force an immediate transition to `Open` (e.g. ahead of a planned
     * upstream downtime), resetting all counters.
     */
    public function forceOpen(): void
    {
        $transition = $this->storageOperation(
            StorageOperation::ForceState,
            fn(): ?CircuitTransition => $this->storage->forceState(
                $this->config->name(),
                CircuitState::Open,
                $this->clock->now(),
            ),
        );
        $this->observe($transition);
    }

    /**
     * Force an immediate transition to `Closed` (reset), resetting all
     * counters.
     */
    public function forceClosed(): void
    {
        $transition = $this->storageOperation(
            StorageOperation::ForceState,
            fn(): ?CircuitTransition => $this->storage->forceState(
                $this->config->name(),
                CircuitState::Closed,
                $this->clock->now(),
            ),
        );
        $this->observe($transition);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function storageOperation(StorageOperation $operationName, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            throw new StorageFailure($operationName->value, $this->config->name(), $e);
        }
    }

    /** @return non-empty-string */
    private function nextAttemptId(): string
    {
        return $this->attemptPrefix . ':' . ++$this->attemptCounter;
    }

    private function retryAfter(StateRecord $record): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return match ($record->state()) {
            CircuitState::Open => max(
                $now,
                $record->openedAt()->modify(
                    sprintf('+%d microseconds', $this->config->cooldown()->toMicros()),
                ),
            ),
            CircuitState::HalfOpen => $now->modify(
                sprintf('+%d microseconds', $this->config->probeTimeout()->toMicros()),
            ),
            CircuitState::Closed => $now,
        };
    }

    /**
     * @param non-empty-string $key
     * @param non-empty-string $attemptId
     */
    private function recordOutcome(
        string $key,
        Outcome $outcome,
        Admission $admission,
        \DateTimeImmutable $admittedAt,
        string $attemptId,
    ): void {
        /** @var non-empty-string $key */
        /** @var non-empty-string $attemptId */
        $result = $this->storageOperation(
            StorageOperation::RecordOutcome,
            fn(): OutcomeResult => $this->storage->recordOutcome(
                $key,
                $outcome,
                $this->config,
                $this->clock->now(),
                $admission,
                $admittedAt,
                $attemptId,
            ),
        );
        $this->observe($result->transition());
    }

    private function observe(?CircuitTransition $transition): void
    {
        if ($transition === null || $this->observer === null) {
            return;
        }

        try {
            $this->observer->onTransition($transition);
        } catch (\Throwable $e) {
            $handler = $this->observerErrorHandler;
            if ($handler !== null) {
                $handler($e, $transition);
            }
        }
    }
}
