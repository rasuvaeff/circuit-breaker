<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

// Metrics::state()/successes()/failures()/rejected() map directly onto
// Prometheus counter/gauge lines - no rasuvaeff/yii3-metrics-prometheus
// dependency needed, this is just the exposition-format shape.

$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'payments-api',
        failureThreshold: Ratio::of(failures: 2, window: 5, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 1,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new InMemoryStorage(),
    clock: new SystemClock(),
);

try {
    $cb->call(static function (): never {
        throw new \RuntimeException('boom');
    });
} catch (\RuntimeException) {
    // recorded below via metrics()
}

$metrics = $cb->metrics();

printf("# HELP circuit_breaker_state Current breaker state (0=closed, 1=open, 2=half-open).\n");
printf("# TYPE circuit_breaker_state gauge\n");
printf(
    "circuit_breaker_state{name=\"payments-api\"} %d\n",
    match ($metrics->state()) {
        CircuitState::Closed => 0,
        CircuitState::Open => 1,
        CircuitState::HalfOpen => 2,
    },
);

printf("# HELP circuit_breaker_successes_total Successes recorded in the current window/episode.\n");
printf("# TYPE circuit_breaker_successes_total counter\n");
printf("circuit_breaker_successes_total{name=\"payments-api\"} %d\n", $metrics->successes());

printf("# HELP circuit_breaker_failures_total Failures recorded in the current window/episode.\n");
printf("# TYPE circuit_breaker_failures_total counter\n");
printf("circuit_breaker_failures_total{name=\"payments-api\"} %d\n", $metrics->failures());

printf("# HELP circuit_breaker_rejected_total Calls rejected since the breaker most recently opened.\n");
printf("# TYPE circuit_breaker_rejected_total counter\n");
printf("circuit_breaker_rejected_total{name=\"payments-api\"} %d\n", $metrics->rejected());
