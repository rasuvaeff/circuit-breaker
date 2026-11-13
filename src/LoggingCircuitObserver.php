<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Ready-made {@see CircuitObserver} that mirrors committed transitions into a
 * PSR-3 logger with severity derived from the transition itself.
 *
 * Levels: `Closed → Open` and `HalfOpen → Open` (including a failed probe and
 * a forced open) are errors — the breaker stops (or keeps stopping) passing
 * traffic; entering `HalfOpen` is a warning — recovery is being attempted but
 * not guaranteed; reaching `Closed` (probe succeeded or forced closed) is
 * info, and so is `HalfOpen → HalfOpen` — a probe completed but did not
 * change the state.
 *
 * Every record carries the breaker name, the previous and new state, the
 * transition reason, the committed counters (`successes`, `failures`,
 * `rejected`), and the transition timestamp, so no extra `snapshot()` read is
 * needed to give the log line context.
 *
 * @api
 */
final readonly class LoggingCircuitObserver implements CircuitObserver
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function onTransition(CircuitTransition $transition): void
    {
        $state = $transition->state();
        $from = $transition->from();
        $to = $transition->to();

        $this->logger->log(
            $this->levelFor($from, $to),
            $from === CircuitState::HalfOpen && $to === CircuitState::HalfOpen
                ? 'Circuit breaker {breaker} probe did not change state'
                : 'Circuit breaker {breaker} transitioned from {from} to {to}',
            [
                'breaker' => $transition->breakerName(),
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $transition->reason()->value,
                'successes' => $state->successes(),
                'failures' => $state->failures(),
                'rejected' => $state->rejected(),
                'occurred_at' => $transition->occurredAt()->format('Y-m-d\TH:i:s.uP'),
            ],
        );
    }

    private function levelFor(CircuitState $from, CircuitState $to): string
    {
        return match ([$from, $to]) {
            [CircuitState::Closed, CircuitState::Open],
            [CircuitState::HalfOpen, CircuitState::Open],
            [CircuitState::Open, CircuitState::Open] => LogLevel::ERROR,
            [CircuitState::Open, CircuitState::HalfOpen],
            [CircuitState::Closed, CircuitState::HalfOpen] => LogLevel::WARNING,
            [CircuitState::HalfOpen, CircuitState::Closed],
            [CircuitState::Open, CircuitState::Closed],
            [CircuitState::Closed, CircuitState::Closed],
            [CircuitState::HalfOpen, CircuitState::HalfOpen] => LogLevel::INFO,
        };
    }
}
