<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Immutable transition emitted after the atomic storage update commits.
 *
 * @api
 */
final readonly class CircuitTransition
{
    /** @param non-empty-string $breakerName */
    public function __construct(
        private string $breakerName,
        private CircuitState $from,
        private CircuitState $to,
        private \DateTimeImmutable $occurredAt,
        private TransitionReason $reason,
        private StateRecord $state,
    ) {}

    /** @return non-empty-string */
    public function breakerName(): string
    {
        return $this->breakerName;
    }

    public function from(): CircuitState
    {
        return $this->from;
    }

    public function to(): CircuitState
    {
        return $this->to;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function reason(): TransitionReason
    {
        return $this->reason;
    }

    public function state(): StateRecord
    {
        return $this->state;
    }
}
