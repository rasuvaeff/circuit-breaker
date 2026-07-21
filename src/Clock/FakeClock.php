<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Clock;

use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * Controllable clock for testing cooldown/window expiry. Not thread-safe —
 * intended for single-process tests.
 *
 * @api
 */
final class FakeClock implements PsrClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('2025-01-01T00:00:00.000000+00:00');
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceMs(int $ms): void
    {
        if ($ms < 0) {
            throw new \InvalidArgumentException('Milliseconds must be non-negative');
        }

        $this->now = $this->now->modify("+{$ms} milliseconds");
    }
}
