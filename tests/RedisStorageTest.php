<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitScriptRunner;
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
}
