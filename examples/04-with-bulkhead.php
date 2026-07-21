<?php

declare(strict_types=1);

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

// rasuvaeff/bulkhead is an optional composition partner, not a dependency of
// this package: bulkhead limits parallelism, the breaker blocks after
// cascading failure - they nest ($bulkhead->call(fn () => $cb->call(...))).
// This example self-skips unless it is installed alongside circuit-breaker.
if (!class_exists(SharedBulkhead::class)) {
    fwrite(STDERR, "composer require --dev rasuvaeff/bulkhead to run this example.\n");

    exit(0);
}

$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'flaky-service',
        failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 3,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new InMemoryStorage(),
    clock: new SystemClock(),
);

$bulkhead = new SharedBulkhead(
    name: 'flaky-service',
    maxConcurrent: 10,
    store: new InMemoryBulkheadStore(),
    lease: Duration::seconds(5),
    maxWait: Duration::zero(),
);

$result = $bulkhead->call(static fn(): mixed => $cb->call(
    static fn(): string => 'called downstream',
));

printf("result: %s\n", $result);
printf("breaker state: %s, bulkhead available slots: %d\n", $cb->state()->value, $bulkhead->availableSlots());
