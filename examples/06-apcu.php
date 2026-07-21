<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\ApcuStorage;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!ApcuStorage::isAvailable()) {
    fwrite(STDERR, "Enable ext-apcu (and apc.enable_cli=1 for CLI) to run this example.\n");

    exit(0);
}

// Single-host cross-process state: every PHP-FPM worker on this machine
// constructing a CircuitBreaker with the same breaker name shares one state
// machine via APCu's shared memory segment.
$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'legacy-api',
        failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 3,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new ApcuStorage(),
    clock: new SystemClock(),
);

$result = $cb->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("state: %s\n", $cb->state()->value);
