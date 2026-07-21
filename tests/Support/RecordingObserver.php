<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support;

use Rasuvaeff\CircuitBreaker\CircuitObserver;
use Rasuvaeff\CircuitBreaker\CircuitTransition;

/**
 * Records every committed transition so a test can assert both that an event
 * fired and what it carried.
 */
final class RecordingObserver implements CircuitObserver
{
    /** @var list<CircuitTransition> */
    public array $events = [];

    #[\Override]
    public function onTransition(CircuitTransition $transition): void
    {
        $this->events[] = $transition;
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return array_map(
            static fn(CircuitTransition $t): string => $t->reason()->value,
            $this->events,
        );
    }
}
