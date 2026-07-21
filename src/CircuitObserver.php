<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Receives committed circuit state transitions for telemetry or logging.
 *
 * @api
 */
interface CircuitObserver
{
    public function onTransition(CircuitTransition $transition): void;
}
