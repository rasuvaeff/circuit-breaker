<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Observability snapshot of a breaker, for exporting to a metrics backend
 * (e.g. `yii3-metrics`). See {@see StateRecord} for the exact meaning of
 * `successes`/`failures` per state.
 *
 * @api
 */
final readonly class Metrics
{
    public function __construct(
        private CircuitState $state,
        private int $successes,
        private int $failures,
        private int $rejected,
        private \DateTimeImmutable $openedAt,
    ) {}

    public static function fromStateRecord(StateRecord $record): self
    {
        return new self(
            state: $record->state(),
            successes: $record->successes(),
            failures: $record->failures(),
            rejected: $record->rejected(),
            openedAt: $record->openedAt(),
        );
    }

    public function state(): CircuitState
    {
        return $this->state;
    }

    public function successes(): int
    {
        return $this->successes;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function rejected(): int
    {
        return $this->rejected;
    }

    public function openedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }
}
