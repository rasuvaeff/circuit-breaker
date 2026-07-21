<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\CircuitBreaker\Redis\LuaScripts;

/**
 * Cross-host {@see Storage} backed by Redis.
 *
 * `admit()`, `recordOutcome()`, `snapshot()`, and `forceState()` each run as
 * exactly one Lua script (see {@see LuaScripts}) — read, evaluate, transition
 * all happen inside Redis's single-threaded script execution, so two workers
 * racing on the same breaker can never interleave a check with a write.
 * Client-agnostic via {@see CircuitScriptRunner} — {@see Redis\PredisScriptRunner}
 * for `predis/predis`, {@see Redis\PhpRedisScriptRunner} for `ext-redis`.
 *
 * @api
 */
final readonly class RedisStorage implements Storage
{
    public function __construct(
        private CircuitScriptRunner $runner,
        private string $keyPrefix = 'circuit-breaker:',
        private bool $useServerTime = true,
    ) {}

    #[\Override]
    public function admit(
        string $key,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        string $attemptId,
    ): AdmissionResult {
        /** @var mixed $reply */
        $reply = $this->runner->run(
            LuaScripts::ADMIT,
            [$this->hashKey($key), $this->probesKey($key)],
            [
                $this->toMillis($now),
                $config->cooldown()->toMillis(),
                $config->probeLimit(),
                $config->probeTimeout()->toMillis(),
                $this->useServerTime ? 1 : 0,
                $attemptId,
            ],
        );

        if (!is_array($reply) || count($reply) !== 6) {
            throw new \RuntimeException('Unexpected Redis reply shape for circuit admission');
        }

        $fields = array_values($reply);
        $admission = match ((string) $fields[0]) {
            'allowed' => Admission::Allowed,
            'probe' => Admission::Probe,
            'rejected' => Admission::Rejected,
            default => throw new \RuntimeException(sprintf('Unexpected Redis admission reply "%s"', (string) $fields[0])),
        };
        $state = $admission === Admission::Probe ? CircuitState::HalfOpen : CircuitState::from((string) $fields[1]);
        $record = new StateRecord(
            state: $state,
            openedAt: $this->fromMillis((int) $fields[3]),
            successes: 0,
            failures: 0,
            rejected: (int) $fields[4],
        );

        return new AdmissionResult(
            admission: $admission,
            transition: $this->transition(
                key: $key,
                from: (string) $fields[1],
                reason: (string) $fields[2],
                occurredAt: $this->fromMillis((int) $fields[5]),
                state: $record,
            ),
        );
    }

    /**
     * `$admittedAt` is accepted for contract symmetry but deliberately unused:
     * Redis fences reclaimed probes by `$attemptId` membership in the probes
     * SET, which needs no clock agreement between the caller and the server.
     */
    #[\Override]
    public function recordOutcome(
        string $key,
        Outcome $outcome,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        Admission $admission,
        \DateTimeImmutable $admittedAt,
        string $attemptId,
    ): OutcomeResult {
        $ratio = $config->failureThreshold();
        /** @var mixed $reply */
        $reply = $this->runner->run(
            LuaScripts::RECORD_OUTCOME,
            [$this->hashKey($key), $this->ringKey($key), $this->probesKey($key)],
            [
                $this->toMillis($now),
                $this->outcomeName($outcome),
                $ratio->failures(),
                $ratio->window(),
                $ratio->within()->toMillis(),
                $config->successThreshold(),
                $this->admissionName($admission),
                $this->useServerTime ? 1 : 0,
                $attemptId,
            ],
        );

        if (!is_array($reply) || count($reply) !== 8) {
            throw new \RuntimeException('Unexpected Redis reply shape for circuit outcome');
        }

        $fields = array_values($reply);
        $state = $this->toStateRecord(array_slice($fields, 0, 5));

        return new OutcomeResult(
            state: $state,
            transition: $this->transition(
                key: $key,
                from: (string) $fields[5],
                reason: (string) $fields[6],
                occurredAt: $this->fromMillis((int) $fields[7]),
                state: $state,
            ),
        );
    }

    #[\Override]
    public function snapshot(string $key): StateRecord
    {
        /** @var mixed $reply */
        $reply = $this->runner->run(
            LuaScripts::SNAPSHOT,
            [$this->hashKey($key), $this->ringKey($key)],
            [],
        );

        return $this->toStateRecord($reply);
    }

    #[\Override]
    public function forceState(string $key, CircuitState $state, \DateTimeImmutable $now): ?CircuitTransition
    {
        /** @var mixed $reply */
        $reply = $this->runner->run(
            LuaScripts::FORCE_STATE,
            [$this->hashKey($key), $this->ringKey($key), $this->probesKey($key)],
            [$state->value, $this->toMillis($now), $this->useServerTime ? 1 : 0],
        );

        if (!is_array($reply) || count($reply) !== 2) {
            throw new \RuntimeException('Unexpected Redis reply shape for forced circuit state');
        }

        $fields = array_values($reply);
        $from = CircuitState::from((string) $fields[0]);
        if ($from === $state) {
            return null;
        }

        $occurredAt = $this->fromMillis((int) $fields[1]);
        $record = new StateRecord($state, $occurredAt, 0, 0, 0);

        return new CircuitTransition(
            breakerName: $key,
            from: $from,
            to: $state,
            occurredAt: $occurredAt,
            reason: $this->forcedReason($state),
            state: $record,
        );
    }

    private function toStateRecord(mixed $reply): StateRecord
    {
        if (!is_array($reply) || count($reply) !== 5) {
            throw new \RuntimeException('Unexpected Redis reply shape for circuit breaker state');
        }

        $fields = array_values($reply);

        return new StateRecord(
            state: CircuitState::from((string) $fields[0]),
            openedAt: $this->fromMillis((int) $fields[1]),
            successes: (int) $fields[2],
            failures: (int) $fields[3],
            rejected: (int) $fields[4],
        );
    }

    private function outcomeName(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Success => 'success',
            Outcome::Failure => 'failure',
            Outcome::Ignored => 'ignored',
        };
    }

    private function admissionName(Admission $admission): string
    {
        return match ($admission) {
            Admission::Allowed => 'allowed',
            Admission::Probe => 'probe',
            Admission::Rejected => 'rejected',
        };
    }

    /** @param non-empty-string $key */
    private function transition(
        string $key,
        string $from,
        string $reason,
        \DateTimeImmutable $occurredAt,
        StateRecord $state,
    ): ?CircuitTransition {
        if ($reason === '') {
            return null;
        }

        return new CircuitTransition(
            breakerName: $key,
            from: CircuitState::from($from),
            to: $state->state(),
            occurredAt: $occurredAt,
            reason: TransitionReason::from($reason),
            state: $state,
        );
    }

    private function forcedReason(CircuitState $state): TransitionReason
    {
        return match ($state) {
            CircuitState::Open => TransitionReason::ForcedOpen,
            CircuitState::Closed => TransitionReason::ForcedClosed,
            CircuitState::HalfOpen => TransitionReason::ForcedHalfOpen,
        };
    }

    private function toMillis(\DateTimeImmutable $t): int
    {
        return (int) floor(((float) $t->format('U.u')) * 1000.0);
    }

    private function fromMillis(int $ms): \DateTimeImmutable
    {
        $seconds = intdiv($ms, 1_000);
        $millis = $ms % 1_000;

        return (new \DateTimeImmutable('@' . $seconds))->modify(sprintf('+%d milliseconds', $millis));
    }

    private function hashKey(string $key): string
    {
        return $this->keyPrefix . '{' . $key . '}';
    }

    private function ringKey(string $key): string
    {
        return $this->hashKey($key) . ':ring';
    }

    private function probesKey(string $key): string
    {
        return $this->hashKey($key) . ':probes';
    }
}
