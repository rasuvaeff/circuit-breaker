<?php

declare(strict_types=1);

use Predis\Client;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('REDIS_HOST');
if ($host === false || $host === '') {
    fwrite(STDERR, "Set REDIS_HOST (and optionally REDIS_PORT) to run this example.\n");

    exit(0);
}

$client = new Client([
    'host' => $host,
    'port' => (int) (getenv('REDIS_PORT') ?: '6379'),
]);

// Every process/host constructing a CircuitBreaker with the same breaker
// name and a RedisStorage pointed at the same Redis shares one state
// machine - a failure recorded on host A is visible to host B's very next
// admit() call.
$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'payments-api',
        failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 3,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new RedisStorage(new PredisScriptRunner($client)),
    clock: new SystemClock(),
);

$result = $cb->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("state: %s\n", $cb->state()->value);
