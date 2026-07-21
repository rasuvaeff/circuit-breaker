<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\Retry;

require dirname(__DIR__) . '/vendor/autoload.php';

// rasuvaeff/retry is an optional composition partner, not a dependency of
// this package (see "Связь с существующими пакетами" in the plan): retry
// repeats transient failures INSIDE one call, the breaker blocks AFTER a
// systemic outage - orthogonal concerns. This example self-skips unless it
// is installed alongside circuit-breaker.
if (!class_exists(Retry::class)) {
    fwrite(STDERR, "composer require --dev rasuvaeff/retry to run this example.\n");

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

$attempt = 0;
$result = $cb->call(callback: static function () use (&$attempt): string {
    return Retry::new()
        ->maxAttempts(3)
        ->withExponential(baseMs: 10, multiplier: 2.0, capMs: 100)
        ->run(static function () use (&$attempt): string {
            ++$attempt;
            if ($attempt < 2) {
                throw new \RuntimeException('transient error');
            }

            return 'succeeded';
        });
});

printf("result: %s (after %d retry attempt(s))\n", $result, $attempt);
printf("breaker state: %s\n", $cb->state()->value);
