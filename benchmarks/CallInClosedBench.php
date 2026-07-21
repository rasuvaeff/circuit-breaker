<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Benchmarks;

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Testo\Bench;

final class CallInClosedBench
{
    private static ?CircuitBreaker $cb = null;

    #[Bench(
        callables: [
            'plain' => [self::class, 'plainCall'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function guardedCall(): int
    {
        return self::breaker()->call(static fn (): int => 42);
    }

    public static function plainCall(): int
    {
        return 42;
    }

    private static function breaker(): CircuitBreaker
    {
        if (self::$cb === null) {
            self::$cb = new CircuitBreaker(
                config: new BreakerConfig(
                    name: 'bench',
                    failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
                    cooldown: Duration::seconds(30),
                    successThreshold: 1,
                    isFailure: static fn(\Throwable $e): bool => true,
                ),
                storage: new InMemoryStorage(),
                clock: new SystemClock(),
            );
        }

        return self::$cb;
    }
}
