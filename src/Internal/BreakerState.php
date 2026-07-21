<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Internal;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\StateRecord;
use Rasuvaeff\CircuitBreaker\TransitionReason;

/**
 * Pure transition logic shared by every {@see \Rasuvaeff\CircuitBreaker\Storage}
 * backend that can hold the whole entry in a single PHP value
 * ({@see \Rasuvaeff\CircuitBreaker\InMemoryStorage},
 * {@see \Rasuvaeff\CircuitBreaker\ApcuStorage}). Each method is a plain
 * function from one `Entry` to the next — it has no side effects and takes no
 * lock itself, so it can be reused verbatim inside either backend's own
 * critical section (a PHP-process-local sequential call for `InMemoryStorage`,
 * an `apcu_add` spinlock for `ApcuStorage`). `RedisStorage` reimplements the
 * same rules in Lua, since a PHP function cannot run inside a Redis script.
 *
 * @internal
 *
 * @psalm-type Entry = array{
 *     state: CircuitState,
 *     openedAt: \DateTimeImmutable,
 *     ring: list<array{ts: float, failure: bool}>,
 *     probeSuccesses: int,
 *     probeSlots: int,
 *     probeGenerationAt: \DateTimeImmutable,
 *     rejected: int,
 * }
 */
final class BreakerState
{
    private function __construct() {}

    /**
     * @return Entry
     */
    public static function fresh(\DateTimeImmutable $now): array
    {
        return [
            'state' => CircuitState::Closed,
            'openedAt' => $now,
            'ring' => [],
            'probeSuccesses' => 0,
            'probeSlots' => 0,
            'probeGenerationAt' => $now,
            'rejected' => 0,
        ];
    }

    /**
     * @param Entry $entry
     *
     * @return array{entry: Entry, admission: Admission, reason: ?TransitionReason}
     */
    public static function admit(array $entry, BreakerConfig $config, \DateTimeImmutable $now): array
    {
        $reason = null;

        if ($entry['state'] === CircuitState::Open) {
            $cooldownElapsed = self::toEpochSeconds($now)
                >= self::toEpochSeconds($entry['openedAt']) + $config->cooldown()->toSeconds();

            if ($cooldownElapsed) {
                $entry['state'] = CircuitState::HalfOpen;
                $entry['probeSuccesses'] = 0;
                $entry['probeSlots'] = 0;
                $entry['probeGenerationAt'] = $now;
                $reason = TransitionReason::CooldownElapsed;
            } else {
                ++$entry['rejected'];

                return ['entry' => $entry, 'admission' => Admission::Rejected, 'reason' => null];
            }
        }

        if ($entry['state'] === CircuitState::HalfOpen) {
            $probeLeaseExpired = $entry['probeSlots'] > 0
                && self::toEpochSeconds($now) >= self::toEpochSeconds($entry['probeGenerationAt'])
                    + $config->probeTimeout()->toSeconds();

            if ($probeLeaseExpired) {
                $entry['probeSlots'] = 0;
                $entry['probeGenerationAt'] = $now;
            } elseif ($entry['probeSlots'] === 0) {
                $entry['probeGenerationAt'] = $now;
            }

            if ($entry['probeSlots'] < $config->probeLimit()) {
                ++$entry['probeSlots'];

                return ['entry' => $entry, 'admission' => Admission::Probe, 'reason' => $reason];
            }

            ++$entry['rejected'];

            return ['entry' => $entry, 'admission' => Admission::Rejected, 'reason' => null];
        }

        return ['entry' => $entry, 'admission' => Admission::Allowed, 'reason' => null];
    }

    /**
     * @param Entry $entry
     *
     * @return array{entry: Entry, reason: ?TransitionReason}
     */
    public static function recordOutcome(
        array $entry,
        Outcome $outcome,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        Admission $admission,
        \DateTimeImmutable $admittedAt,
    ): array {
        if ($admission === Admission::Probe && $admittedAt < $entry['probeGenerationAt']) {
            return ['entry' => $entry, 'reason' => null];
        }

        if ($entry['state'] === CircuitState::Closed) {
            if ($admission === Admission::Probe) {
                return self::recordStaleProbeInClosed($entry, $outcome, $now);
            }

            return self::recordInClosed($entry, $outcome, $config, $now);
        }

        if ($entry['state'] === CircuitState::HalfOpen) {
            // Only a probe of the CURRENT generation may move HalfOpen. An
            // `Allowed` outcome was admitted while the breaker was still
            // Closed: by the time it lands the circuit has opened and started
            // a fresh probe generation, so counting it would let a stale
            // success close the circuit early (or a stale failure reopen it).
            if ($admission !== Admission::Probe) {
                return ['entry' => $entry, 'reason' => null];
            }

            return self::recordInHalfOpen($entry, $outcome, $config, $now);
        }

        return ['entry' => $entry, 'reason' => null];
    }

    /**
     * @param Entry $entry
     *
     * @return array{entry: Entry, reason: ?TransitionReason}
     */
    private static function recordStaleProbeInClosed(
        array $entry,
        Outcome $outcome,
        \DateTimeImmutable $now,
    ): array {
        $reason = null;

        if ($outcome === Outcome::Failure) {
            $entry['state'] = CircuitState::Open;
            $entry['openedAt'] = $now;
            $entry['rejected'] = 0;
            $reason = TransitionReason::ProbeFailed;
        }

        return ['entry' => $entry, 'reason' => $reason];
    }

    /**
     * @param Entry $entry
     */
    public static function toStateRecord(array $entry): StateRecord
    {
        $successes = count(array_filter($entry['ring'], static fn(array $e): bool => !$e['failure']));
        $failures = count(array_filter($entry['ring'], static fn(array $e): bool => $e['failure']));

        if ($entry['state'] === CircuitState::HalfOpen) {
            $successes = $entry['probeSuccesses'];
            $failures = 0;
        }

        return new StateRecord(
            state: $entry['state'],
            openedAt: $entry['openedAt'],
            successes: $successes,
            failures: $failures,
            rejected: $entry['rejected'],
        );
    }

    public static function toEpochSeconds(\DateTimeImmutable $t): float
    {
        return (float) $t->format('U.u');
    }

    /**
     * @param Entry $entry
     *
     * @return array{entry: Entry, reason: ?TransitionReason}
     */
    private static function recordInClosed(array $entry, Outcome $outcome, BreakerConfig $config, \DateTimeImmutable $now): array
    {
        if ($outcome === Outcome::Ignored) {
            return ['entry' => $entry, 'reason' => null];
        }

        $ratio = $config->failureThreshold();
        $entry['ring'][] = ['ts' => self::toEpochSeconds($now), 'failure' => $outcome === Outcome::Failure];

        if (count($entry['ring']) > $ratio->window()) {
            $entry['ring'] = array_slice($entry['ring'], -$ratio->window());
        }

        $cutoff = self::toEpochSeconds($now) - $ratio->within()->toSeconds();
        $entry['ring'] = array_values(array_filter(
            $entry['ring'],
            static fn(array $e): bool => $e['ts'] >= $cutoff,
        ));

        $failures = count(array_filter($entry['ring'], static fn(array $e): bool => $e['failure']));

        if ($failures >= $ratio->failures()) {
            $entry['state'] = CircuitState::Open;
            $entry['openedAt'] = $now;
            $entry['rejected'] = 0;

            return ['entry' => $entry, 'reason' => TransitionReason::FailureThresholdReached];
        }

        return ['entry' => $entry, 'reason' => null];
    }

    /**
     * @param Entry $entry
     *
     * @return array{entry: Entry, reason: ?TransitionReason}
     */
    private static function recordInHalfOpen(array $entry, Outcome $outcome, BreakerConfig $config, \DateTimeImmutable $now): array
    {
        $entry['probeSlots'] = max(0, $entry['probeSlots'] - 1);

        if ($outcome === Outcome::Success) {
            ++$entry['probeSuccesses'];

            if ($entry['probeSuccesses'] >= $config->successThreshold()) {
                $entry['state'] = CircuitState::Closed;
                $entry['ring'] = [];
                $entry['rejected'] = 0;
                $entry['probeSuccesses'] = 0;
                $entry['probeSlots'] = 0;

                return ['entry' => $entry, 'reason' => TransitionReason::ProbeSucceeded];
            }
        } elseif ($outcome === Outcome::Failure) {
            $entry['state'] = CircuitState::Open;
            $entry['openedAt'] = $now;
            $entry['probeSuccesses'] = 0;
            $entry['probeSlots'] = 0;

            return ['entry' => $entry, 'reason' => TransitionReason::ProbeFailed];
        }

        return ['entry' => $entry, 'reason' => null];
    }
}
