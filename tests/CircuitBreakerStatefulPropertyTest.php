<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine\BreakerAction;
use Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine\BreakerCommand;
use Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine\BreakerHarness;
use Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine\BreakerModel;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Model-based properties over a breaker's storage, generated with
 * {@see Gen::swarm()}.
 *
 * Uniform command sequences almost always contain everything: a thirty-step
 * run that never records a success, or never advances the clock, is a coin
 * flipped thirty times. Those are exactly the runs a breaker's interesting
 * invariants live in — "a probe was never due", "nothing ever succeeded" — so
 * the sequence generator is swarmed, and each case may use only part of the
 * command alphabet.
 */
#[Test]
#[Covers(InMemoryStorage::class)]
final class CircuitBreakerStatefulPropertyTest
{
    private const int COOLDOWN_SECONDS = 30;

    #[Property(runs: 150, timeoutMs: 2000)]
    public function invariantsHoldAcrossAnyCommandSequence(CommandSequence $sequence): void
    {
        $harness = null;

        StateMachine::check(
            $sequence,
            function () use (&$harness): BreakerHarness {
                return $harness = new BreakerHarness(
                    storage: new InMemoryStorage(),
                    config: $this->config(),
                    now: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            },
        );

        \assert($harness instanceof BreakerHarness);

        $names = array_map(static fn(object $command): string => (string) $command, $sequence->commands);
        $state = $harness->storage->snapshot(BreakerHarness::KEY)->state();

        // What swarm is for: the runs that lack an operation entirely. Under a
        // uniform generator these are rare enough to be theoretical.
        Classify::when(!$this->contains($names, 'RecordSuccess'), 'never succeeded');
        Classify::when(!$this->contains($names, 'Advance'), 'time never moved');
        Classify::when($state === CircuitState::Open, 'ended open');
        Classify::when($state === CircuitState::HalfOpen, 'ended half-open');

        // The gate that makes the swarm claim a test rather than a comment: if
        // the alphabet stops being restricted per case, this floor is the
        // thing that fails.
        Classify::cover(!$this->contains($names, 'RecordSuccess'), 'no-success runs', 10.0);

        // A breaker that never heard about a failure is closed, whatever else
        // the sequence did to it.
        if (!$this->contains($names, 'RecordFailure')) {
            Assert::same($state, CircuitState::Closed);
        }

        // And the invariant swarm exists to reach: half-open is a fact about
        // time. A sequence that never advanced the clock cannot have waited
        // out a cooldown, however many failures and admissions it contains.
        if (!$this->contains($names, 'Advance')) {
            Assert::false($state === CircuitState::HalfOpen);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function invariantsHoldAcrossAnyCommandSequenceGenerators(): array
    {
        return [
            'sequence' => Gen::swarm(Gen::commands(
                new BreakerModel(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
                [
                    Gen::constant(new BreakerCommand(BreakerAction::RecordSuccess)),
                    Gen::constant(new BreakerCommand(BreakerAction::RecordFailure)),
                    Gen::constant(new BreakerCommand(BreakerAction::Admit)),
                    Gen::map(
                        Gen::intBetween(1, 120),
                        static fn(int $seconds): BreakerCommand => new BreakerCommand(BreakerAction::Advance, $seconds),
                    ),
                ],
                minLength: 0,
                maxLength: 20,
            )),
        ];
    }

    /**
     * @param list<string> $names
     */
    private function contains(array $names, string $prefix): bool
    {
        foreach ($names as $name) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function config(): BreakerConfig
    {
        return new BreakerConfig(
            name: 'stateful',
            failureThreshold: Ratio::of(failures: 3, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(self::COOLDOWN_SECONDS),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
