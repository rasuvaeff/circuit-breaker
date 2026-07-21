<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitScriptRunner;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$runner = new class implements CircuitScriptRunner {
    #[\Override]
    public function run(string $script, array $keys, array $args): mixed
    {
        throw new \RuntimeException('Redis is unavailable');
    }
};

$breaker = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'payments-api',
        failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 1,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new RedisStorage($runner),
    clock: new SystemClock(),
);

try {
    $breaker->call(static fn(): string => 'never reached');
} catch (StorageFailure $e) {
    fwrite(STDERR, sprintf("State storage unavailable during %s: %s\n", $e->operation, $e->getPrevious()?->getMessage()));
}
