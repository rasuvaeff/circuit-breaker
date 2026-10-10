<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Opt-in extension of {@see CircuitObserver} for observers that also need
 * the current state outside of transitions — typically a metrics gauge,
 * whose series does not exist until the breaker first changes state.
 *
 * A breaker never calls {@see onSnapshot()} on its own: it is delivered by
 * {@see CircuitBreaker::publishState()}, so the application decides when the
 * storage read happens (once at boot, or on every metrics scrape).
 *
 * @api
 */
interface CircuitSnapshotObserver extends CircuitObserver
{
    public function onSnapshot(string $breakerName, Metrics $metrics): void;
}
