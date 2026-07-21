<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Atomic result of {@see Storage::admit()}.
 *
 * @api
 */
final readonly class AdmissionResult
{
    public function __construct(
        private Admission $admission,
        private ?CircuitTransition $transition = null,
    ) {}

    public function admission(): Admission
    {
        return $this->admission;
    }

    public function transition(): ?CircuitTransition
    {
        return $this->transition;
    }
}
