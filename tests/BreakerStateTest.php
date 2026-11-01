<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Internal\BreakerState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\TransitionReason;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Pins the stored entry itself, not only the state observable through a
 * storage: a counter left at a stale value is invisible until the next
 * HalfOpen generation, but the entry is the contract between the two
 * backends that share this logic.
 */
#[Test]
#[Covers(BreakerState::class)]
final class BreakerStateTest
{
    public function aSucceededProbeLeavesNoProbeResidueInTheClosedEntry(): void
    {
        $now = new \DateTimeImmutable('@100');
        $entry = BreakerState::fresh($now);
        $entry['state'] = CircuitState::HalfOpen;
        $entry['probeSlots'] = 2;
        $entry['probeSuccesses'] = 4;

        $result = BreakerState::recordOutcome($entry, Outcome::Success, $this->config(), $now, Admission::Probe, $now);

        Assert::same($result['reason'], TransitionReason::ProbeSucceeded);
        Assert::same($result['entry']['state'], CircuitState::Closed);
        Assert::same($result['entry']['probeSuccesses'], 0);
        Assert::same($result['entry']['probeSlots'], 0);
    }

    public function aFailedProbeLeavesNoProbeResidueInTheOpenEntry(): void
    {
        $now = new \DateTimeImmutable('@100');
        $entry = BreakerState::fresh($now);
        $entry['state'] = CircuitState::HalfOpen;
        $entry['probeSlots'] = 2;
        $entry['probeSuccesses'] = 4;

        $result = BreakerState::recordOutcome($entry, Outcome::Failure, $this->config(), $now, Admission::Probe, $now);

        Assert::same($result['reason'], TransitionReason::ProbeFailed);
        Assert::same($result['entry']['state'], CircuitState::Open);
        Assert::same($result['entry']['probeSuccesses'], 0);
        Assert::same($result['entry']['probeSlots'], 0);
    }

    public function theRingStaysAListAfterExpiredOutcomesAreEvicted(): void
    {
        $now = new \DateTimeImmutable('@1000');
        $entry = BreakerState::fresh($now);
        $entry['ring'] = [
            ['ts' => 1.0, 'failure' => false],
            ['ts' => 2.0, 'failure' => false],
            ['ts' => 990.0, 'failure' => false],
        ];

        $result = BreakerState::recordOutcome($entry, Outcome::Success, $this->config(), $now, Admission::Allowed, $now);

        Assert::same(count($result['entry']['ring']), 2);
        Assert::true(array_is_list($result['entry']['ring']));
    }

    private function config(): BreakerConfig
    {
        return new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
