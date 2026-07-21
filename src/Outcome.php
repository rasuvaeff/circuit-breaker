<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Outcome of a call admitted by the breaker, as classified by
 * {@see BreakerConfig::isFailure()}.
 *
 * @api
 */
enum Outcome
{
    /** The callback returned normally. */
    case Success;

    /** The callback threw and `isFailure($e) === true` — counts against the failure threshold. */
    case Failure;

    /**
     * The callback threw but `isFailure($e) === false` — does not move the
     * breaker's counters at all. In `HalfOpen` it still releases the occupied
     * probe slot.
     */
    case Ignored;
}
