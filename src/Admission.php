<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Decision returned by {@see Storage::admit()}.
 *
 * @api
 */
enum Admission
{
    /** `Closed` — ordinary call. */
    case Allowed;

    /** `HalfOpen`, a probe slot was occupied — the caller MUST call `recordOutcome()`. */
    case Probe;

    /** `Open`, or `HalfOpen` with no free probe slot — the caller must not invoke the callback. */
    case Rejected;
}
