<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Clock;

use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * @api
 */
final readonly class SystemClock implements PsrClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
