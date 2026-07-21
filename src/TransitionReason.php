<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Why a circuit changed state.
 *
 * @api
 */
enum TransitionReason: string
{
    case CooldownElapsed = 'cooldown-elapsed';
    case FailureThresholdReached = 'failure-threshold-reached';
    case ProbeSucceeded = 'probe-succeeded';
    case ProbeFailed = 'probe-failed';
    case ForcedOpen = 'forced-open';
    case ForcedClosed = 'forced-closed';
    case ForcedHalfOpen = 'forced-half-open';
}
