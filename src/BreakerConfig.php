<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\Duration\Duration;

/**
 * Configuration of a circuit breaker. All thresholds are immutable value
 * objects; the mutation policy itself (windowed counters, cooldown, probe
 * slots) lives in `Storage`, not here.
 *
 * @api
 */
final readonly class BreakerConfig
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /** @var non-empty-string */
    private string $name;

    /** @var \Closure(\Throwable): bool */
    private \Closure $isFailure;

    /** @var \Closure(mixed): Outcome */
    private \Closure $classifyResult;

    private Duration $probeTimeout;

    /**
     * @param string   $name             identifies this breaker; becomes part of the storage
     *                                   key, so it is restricted to `[A-Za-z0-9_.:-]+`
     * @param Ratio    $failureThreshold e.g. 5 failures out of the last 10 calls within 60s
     * @param Duration $cooldown         how long `Open` lasts before a probe is allowed
     * @param int      $successThreshold consecutive probe successes needed for
     *                                   `HalfOpen` → `Closed`, `≥ 1`
     * @param callable(\Throwable): bool $isFailure classifies callback exceptions
     *                                   that indicate a downstream failure
     * @param int      $probeLimit       max concurrent probes admitted in `HalfOpen`, `≥ 1`
     * @param Duration|null $probeTimeout maximum probe lease; defaults to `cooldown`
     * @param (callable(mixed): Outcome)|null $classifyResult classifies normal
     *                                                        callback results;
     *                                                        defaults to Success
     */
    public function __construct(
        string $name,
        private Ratio $failureThreshold,
        private Duration $cooldown,
        private int $successThreshold,
        callable $isFailure,
        private int $probeLimit = 1,
        ?Duration $probeTimeout = null,
        ?callable $classifyResult = null,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid breaker name "%s"', $name));
        }
        if ($cooldown->isZero()) {
            throw new \InvalidArgumentException('Cooldown must be greater than zero');
        }
        if ($successThreshold < 1) {
            throw new \InvalidArgumentException('Success threshold must be greater than or equal to 1');
        }
        if ($probeLimit < 1) {
            throw new \InvalidArgumentException('Probe limit must be greater than or equal to 1');
        }

        $resolvedProbeTimeout = $probeTimeout ?? $cooldown;

        if ($resolvedProbeTimeout->isZero()) {
            throw new \InvalidArgumentException('Probe timeout must be greater than zero');
        }

        $this->name = $name;
        $this->isFailure = \Closure::fromCallable($isFailure);
        $this->classifyResult = \Closure::fromCallable(
            $classifyResult ?? static fn(mixed $result): Outcome => Outcome::Success,
        );
        $this->probeTimeout = $resolvedProbeTimeout;
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    public function failureThreshold(): Ratio
    {
        return $this->failureThreshold;
    }

    public function cooldown(): Duration
    {
        return $this->cooldown;
    }

    public function successThreshold(): int
    {
        return $this->successThreshold;
    }

    public function probeLimit(): int
    {
        return $this->probeLimit;
    }

    public function probeTimeout(): Duration
    {
        return $this->probeTimeout;
    }

    public function isFailure(\Throwable $e): bool
    {
        return ($this->isFailure)($e);
    }

    public function classifyResult(mixed $result): Outcome
    {
        return ($this->classifyResult)($result);
    }
}
