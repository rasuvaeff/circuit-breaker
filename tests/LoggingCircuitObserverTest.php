<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Psr\Log\LogLevel;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\LoggingCircuitObserver;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\CircuitBreaker\Tests\Support\SpyLogger;
use Rasuvaeff\CircuitBreaker\TransitionReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(LoggingCircuitObserver::class)]
final class LoggingCircuitObserverTest
{
    public function logsCommittedTransitionWithLevelAndContext(): void
    {
        $logger = new SpyLogger();
        $observer = new LoggingCircuitObserver($logger);
        $at = new \DateTimeImmutable('@0.123456');
        $state = new StateRecord(CircuitState::Open, $at, 1, 2, 0);
        $transition = new CircuitTransition('payments', CircuitState::Closed, CircuitState::Open, $at, TransitionReason::FailureThresholdReached, $state);

        $observer->onTransition($transition);

        Assert::same(\count($logger->records), 1);
        $record = $logger->records[0];
        Assert::same($record['level'], LogLevel::ERROR);
        Assert::same($record['message'], 'Circuit breaker {breaker} transitioned from {from} to {to}');
        Assert::same($record['context'], [
            'breaker' => 'payments',
            'from' => 'closed',
            'to' => 'open',
            'reason' => 'failure-threshold-reached',
            'successes' => 1,
            'failures' => 2,
            'rejected' => 0,
            'occurred_at' => '1970-01-01T00:00:00.123456+00:00',
        ]);
    }

    #[DataProvider('transitionLevelProvider')]
    public function mapsEveryTransitionToExpectedLevel(
        CircuitState $from,
        CircuitState $to,
        TransitionReason $reason,
        string $expectedLevel,
    ): void {
        $logger = new SpyLogger();
        $observer = new LoggingCircuitObserver($logger);
        $at = new \DateTimeImmutable('@0');
        $state = new StateRecord($to, $at, 0, 0, 0);

        $observer->onTransition(new CircuitTransition('payments', $from, $to, $at, $reason, $state));

        Assert::same(\count($logger->records), 1);
        Assert::same($logger->records[0]['level'], $expectedLevel);
        Assert::same($logger->records[0]['message'], 'Circuit breaker {breaker} transitioned from {from} to {to}');
    }

    public function logsHalfOpenToHalfOpenProbeAsInfo(): void
    {
        $logger = new SpyLogger();
        $observer = new LoggingCircuitObserver($logger);
        $at = new \DateTimeImmutable('@0');
        $state = new StateRecord(CircuitState::HalfOpen, $at, 1, 0, 0);

        $observer->onTransition(
            new CircuitTransition('payments', CircuitState::HalfOpen, CircuitState::HalfOpen, $at, TransitionReason::ProbeSucceeded, $state),
        );

        Assert::same(\count($logger->records), 1);
        Assert::same($logger->records[0]['level'], LogLevel::INFO);
        Assert::same($logger->records[0]['message'], 'Circuit breaker {breaker} probe did not change state');
        Assert::same($logger->records[0]['context']['from'], 'half-open');
        Assert::same($logger->records[0]['context']['to'], 'half-open');
    }

    public static function transitionLevelProvider(): iterable
    {
        yield 'closed to open on threshold' => [
            CircuitState::Closed,
            CircuitState::Open,
            TransitionReason::FailureThresholdReached,
            LogLevel::ERROR,
        ];
        yield 'half-open to open on failed probe' => [
            CircuitState::HalfOpen,
            CircuitState::Open,
            TransitionReason::ProbeFailed,
            LogLevel::ERROR,
        ];
        yield 'forced open while open' => [
            CircuitState::Open,
            CircuitState::Open,
            TransitionReason::ForcedOpen,
            LogLevel::ERROR,
        ];
        yield 'open to half-open after cooldown' => [
            CircuitState::Open,
            CircuitState::HalfOpen,
            TransitionReason::CooldownElapsed,
            LogLevel::WARNING,
        ];
        yield 'forced half-open from closed' => [
            CircuitState::Closed,
            CircuitState::HalfOpen,
            TransitionReason::ForcedHalfOpen,
            LogLevel::WARNING,
        ];
        yield 'half-open to closed after probe' => [
            CircuitState::HalfOpen,
            CircuitState::Closed,
            TransitionReason::ProbeSucceeded,
            LogLevel::INFO,
        ];
        yield 'forced closed from open' => [
            CircuitState::Open,
            CircuitState::Closed,
            TransitionReason::ForcedClosed,
            LogLevel::INFO,
        ];
        yield 'forced closed while closed' => [
            CircuitState::Closed,
            CircuitState::Closed,
            TransitionReason::ForcedClosed,
            LogLevel::INFO,
        ];
    }
}
