<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Integration;

use Predis\Client;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

#[Test]
#[CoversNothing]
final class RedisClusterIntegrationTest
{
    use StorageCalls;

    public function multiKeyScriptsRunWithoutCrossSlotError(): void
    {
        $nodes = getenv('REDIS_CLUSTER_NODES');
        if ($nodes === false || $nodes === '') {
            return;
        }

        $client = new Client(
            array_map(
                static fn(string $node): string => 'tcp://' . $node,
                explode(',', $nodes),
            ),
            ['cluster' => 'redis'],
        );
        $storage = new RedisStorage(new PredisScriptRunner($client));
        $config = new BreakerConfig(
            name: 'cluster-it',
            failureThreshold: Ratio::of(1, 1, Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );

        $storage->forceState('cluster-it', CircuitState::Closed, new \DateTimeImmutable('@0'));
        $record = $this->recordOn(
            $storage,
            'cluster-it',
            Outcome::Failure,
            $config,
            new \DateTimeImmutable('@0'),
        )->state();

        Assert::same($record->state(), CircuitState::Open);
        Assert::same($record->failures(), 1);
    }
}
