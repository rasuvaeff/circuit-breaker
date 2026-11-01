<?php

declare(strict_types=1);

/**
 * TEST-ONLY function shim. Loaded through `autoload-dev.files`, never through
 * the production autoloader: shadowing `apcu_store()` in shipped code would
 * silently break every consumer.
 *
 * PHP resolves an unqualified call inside a namespace to the namespaced
 * function first, so this definition intercepts {@see \Rasuvaeff\CircuitBreaker\ApcuStorage}'s
 * `apcu_store()` calls and falls through to the real extension function unless
 * a test armed {@see \Rasuvaeff\CircuitBreaker\Tests\Support\ApcuStoreFailure}.
 */

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\CircuitBreaker\Tests\Support\ApcuLockProbe;
use Rasuvaeff\CircuitBreaker\Tests\Support\ApcuStoreFailure;

function apcu_store(mixed $key, mixed $value = null, int $ttl = 0): mixed
{
    if (ApcuLockProbe::$armed && is_string($key)) {
        ApcuLockProbe::$storeTtls[$key] = $ttl;
    }

    if (is_string($key) && ApcuStoreFailure::shouldFail($key)) {
        return false;
    }

    return \apcu_store($key, $value, $ttl);
}

function apcu_add(mixed $key, mixed $value = null, int $ttl = 0): mixed
{
    if (ApcuLockProbe::$armed) {
        ++ApcuLockProbe::$adds;
    }

    return \apcu_add($key, $value, $ttl);
}

function usleep(int $microseconds): void
{
    if (ApcuLockProbe::$armed) {
        ++ApcuLockProbe::$sleeps;
        ApcuLockProbe::$sleepMicros[] = $microseconds;
    }

    \usleep($microseconds);
}

function random_int(int $min, int $max): int
{
    if (ApcuLockProbe::$armed) {
        ApcuLockProbe::$tokenLowerBounds[] = $min;
    }

    return \random_int($min, $max);
}
