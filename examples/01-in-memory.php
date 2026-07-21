<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'flaky-service',
        failureThreshold: Ratio::of(failures: 2, window: 5, within: Duration::seconds(60)),
        cooldown: Duration::seconds(5),
        successThreshold: 1,
        isFailure: static fn(\Throwable $e): bool => $e instanceof \RuntimeException,
    ),
    storage: new InMemoryStorage(),
    clock: new SystemClock(),
);

$callFailingService = static function (): never {
    throw new \RuntimeException('downstream unavailable');
};

// Two failures trip the breaker (failureThreshold = 2).
for ($i = 1; $i <= 2; ++$i) {
    try {
        $cb->call($callFailingService);
    } catch (\RuntimeException $e) {
        printf("attempt %d failed: %s\n", $i, $e->getMessage());
    }
}

printf("state after threshold: %s\n", $cb->state()->value);

// Any further call fast-fails without touching the callback, with a fallback
// providing a degraded response.
$result = $cb->call(
    callback: $callFailingService,
    fallback: static function (\Throwable $e): string {
        if ($e instanceof CircuitOpenException) {
            return sprintf('degraded response (retry after %s)', $e->retryAfter->format('H:i:s'));
        }

        return 'degraded response';
    },
);

printf("fallback result: %s\n", $result);
