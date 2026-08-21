<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine;

/**
 * What a test can know about a breaker without reimplementing it.
 *
 * Deliberately not a replica: windowed counters and probe leases are the
 * package's job, and a model that copied them would only assert that the copy
 * matches. This tracks the two facts every invariant below is built from —
 * whether a failure has ever been recorded, and whether the clock has moved
 * far enough since the breaker opened for a probe to be due.
 */
final readonly class BreakerModel
{
    public function __construct(
        public \DateTimeImmutable $now,
        public bool $anyFailureRecorded = false,
        public bool $cooldownCouldHaveElapsed = false,
    ) {}

    public function withFailure(): self
    {
        return new self($this->now, anyFailureRecorded: true, cooldownCouldHaveElapsed: $this->cooldownCouldHaveElapsed);
    }

    public function withTimeAdvancedBy(\DateInterval $interval, bool $pastCooldown): self
    {
        return new self(
            $this->now->add($interval),
            $this->anyFailureRecorded,
            $this->cooldownCouldHaveElapsed || $pastCooldown,
        );
    }
}
