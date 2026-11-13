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
 * info.
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

        $this->logger->log(
            level: $this->levelFor($transition->from(), $transition->to()),
            message: 'Circuit breaker {breaker} transitioned from {from} to {to}',
            context: [
                'breaker' => $transition->breakerName(),
                'from' => $transition->from()->value,
                'to' => $transition->to()->value,
                'reason' => $transition->reason()->value,
                'successes' => $state->successes(),
                'failures' => $state->failures(),
                'rejected' => $state->rejected(),
                'occurred_at' => $transition->occurredAt()->format(\DateTimeInterface::ATOM),
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
            [CircuitState::Closed, CircuitState::Closed] => LogLevel::INFO,
        };
    }
}
