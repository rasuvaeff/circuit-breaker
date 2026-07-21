<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Benchmarks;

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Testo\Bench;

final class StateTransitionBench
{
    private static ?InMemoryStorage $storage = null;
    private static ?BreakerConfig $config = null;
    private static ?\DateTimeImmutable $now = null;

    #[Bench(
        callables: [
            'admitOnly' => [self::class, 'admitOnlyInClosed'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function admitAndRecordSuccessInClosed(): void
    {
        self::storage()->admit('bench', self::config(), self::now());
        self::storage()->recordOutcome('bench', Outcome::Success, self::config(), self::now());
    }

    public static function admitOnlyInClosed(): void
    {
        self::storage()->admit('bench', self::config(), self::now());
    }

    private static function storage(): InMemoryStorage
    {
        return self::$storage ??= new InMemoryStorage();
    }

    private static function config(): BreakerConfig
    {
        return self::$config ??= new BreakerConfig(
            name: 'bench',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return self::$now ??= new \DateTimeImmutable();
    }
}
