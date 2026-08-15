<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine;

/**
 * The four things anything ever does to a breaker's storage.
 *
 * `Advance` is one of them on purpose: a cooldown is a fact about time, so a
 * sequence that never advances the clock is a real scenario — and it is the
 * one where `HalfOpen` must be unreachable.
 */
enum BreakerAction
{
    case RecordSuccess;
    case RecordFailure;
    case Admit;
    case Advance;
}
