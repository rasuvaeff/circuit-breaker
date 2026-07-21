<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * State of a circuit breaker. Enum, not a string — `match` without `default`
 * catches every branch at static-analysis time.
 *
 * @api
 */
enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half-open';
}
