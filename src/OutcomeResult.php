<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Atomic result of {@see Storage::recordOutcome()}.
 *
 * @api
 */
final readonly class OutcomeResult
{
    public function __construct(
        private StateRecord $state,
        private ?CircuitTransition $transition = null,
    ) {}

    public function state(): StateRecord
    {
        return $this->state;
    }

    public function transition(): ?CircuitTransition
    {
        return $this->transition;
    }
}
