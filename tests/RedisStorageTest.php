<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitScriptRunner;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\CircuitTransition;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(RedisStorage::class)]
final class RedisStorageTest
{
    use StorageCalls;

    public function multiKeyScriptsUseOneRedisClusterHashSlot(): void
    {
        $runner = Understudy::for(CircuitScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))
            ->returns(['closed', '0', '1', '0', '0', 'closed', '', '0']);
        $storage = new RedisStorage($runner);
        $config = new BreakerConfig(
            name: 'payments',
            failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );

        $this->recordOn(
            $storage,
            'payments',
            Outcome::Success,
            $config,
            new \DateTimeImmutable('@0'),
            Admission::Allowed,
        )->state();

        $calls = Understudy::calls(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()));
        Assert::same($calls[0]->args[1], [
            'circuit-breaker:{payments}',
            'circuit-breaker:{payments}:ring',
            'circuit-breaker:{payments}:probes',
        ]);
    }

    public function rejectsUnexpectedAdmissionReply(): void
    {
        $runner = Understudy::for(CircuitScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))->returns('garbage');
        $storage = new RedisStorage($runner);
        $config = new BreakerConfig(
            name: 'payments',
            failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );

        Expect::exception(\RuntimeException::class)->withMessageContaining('Unexpected Redis reply shape');

        $this->admitOn($storage, 'payments', $config, new \DateTimeImmutable('@0'))->admission();
    }

    public function rejectsUnknownAdmissionVerb(): void
    {
        $storage = $this->storageReplying(['bogus', 'closed', '0', '0', '0', '0']);

        Expect::exception(\RuntimeException::class)->withMessageContaining('Unexpected Redis admission reply "bogus"');

        $this->admitOn($storage, 'payments', $this->config(), new \DateTimeImmutable('@0'))->admission();
    }

    public function rejectsUnexpectedOutcomeReply(): void
    {
        $storage = $this->storageReplying('garbage');

        Expect::exception(\RuntimeException::class)->withMessageContaining('Unexpected Redis reply shape for circuit outcome');

        $this->recordOn($storage, 'payments', Outcome::Success, $this->config(), new \DateTimeImmutable('@0'));
    }

    public function rejectsUnexpectedForceStateReply(): void
    {
        $storage = $this->storageReplying('garbage');

        Expect::exception(\RuntimeException::class)->withMessageContaining('Unexpected Redis reply shape for forced circuit state');

        $storage->forceState('payments', CircuitState::Open, new \DateTimeImmutable('@0'));
    }

    public function rejectsUnexpectedSnapshotReply(): void
    {
        $storage = $this->storageReplying('garbage');

        Expect::exception(\RuntimeException::class)->withMessageContaining('Unexpected Redis reply shape for circuit breaker state');

        $storage->snapshot('payments');
    }

    public function forcingTheStateTheCircuitAlreadyHasIsNotATransition(): void
    {
        $storage = $this->storageReplying(['open', '1000']);

        Assert::null($storage->forceState('payments', CircuitState::Open, new \DateTimeImmutable('@1')));
    }

    public function forcingADifferentStateReportsTheTransition(): void
    {
        $storage = $this->storageReplying(['closed', '2000']);

        $transition = $storage->forceState('payments', CircuitState::Open, new \DateTimeImmutable('@2'));

        Assert::instanceOf($transition, CircuitTransition::class);
        Assert::same($transition->from(), CircuitState::Closed);
        Assert::same($transition->to(), CircuitState::Open);
    }

    public function serverTimeIsRequestedByDefaultAndCanBeDisabled(): void
    {
        $runner = Understudy::for(CircuitScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))->returns(['closed', '2000']);

        (new RedisStorage($runner))->forceState('payments', CircuitState::Open, new \DateTimeImmutable('@2'));
        (new RedisStorage($runner, useServerTime: false))->forceState('payments', CircuitState::Open, new \DateTimeImmutable('@2'));

        $calls = Understudy::calls(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()));
        Assert::same($calls[0]->args[2][2], 1);
        Assert::same($calls[1]->args[2][2], 0);
    }

    private function storageReplying(mixed $reply): RedisStorage
    {
        $runner = Understudy::for(CircuitScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))->returns($reply);

        return new RedisStorage($runner);
    }

    private function config(): BreakerConfig
    {
        return new BreakerConfig(
            name: 'payments',
            failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
