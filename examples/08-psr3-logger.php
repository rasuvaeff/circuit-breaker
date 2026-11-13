<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\LoggingCircuitObserver;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

// Any PSR-3 logger works; this one just echoes to stdout.
final class EchoLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []): void
    {
        $interpolated = $message;
        foreach ($context as $key => $value) {
            $interpolated = str_replace('{' . $key . '}', (string) $value, $interpolated);
        }
        printf("[%s] %s\n", (string) $level, $interpolated);
    }
}

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
    observer: new LoggingCircuitObserver(new EchoLogger()),
);

$callFailingService = static function (): never {
    throw new \RuntimeException('downstream unavailable');
};

// Two failures trip the breaker — logged as an error.
for ($i = 1; $i <= 2; ++$i) {
    try {
        $cb->call($callFailingService);
    } catch (\RuntimeException) {
    }
}

// With real traffic, the cooldown elapsing (Open → HalfOpen) is a warning and
// a successful probe (HalfOpen → Closed) is info; here the loop just shows
// the error-level record a tripped breaker emits.
printf("state: %s\n", $cb->state()->value);
