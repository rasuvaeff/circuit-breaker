<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support;

/**
 * Toggle for the `apcu_store()` shim (`tests/Support/apcu_store_shim.php`),
 * which shadows the extension function inside the
 * `Rasuvaeff\CircuitBreaker` namespace for the test run only.
 *
 * APCu returns `false` from `apcu_store()` when its shared memory segment is
 * exhausted; there is no way to provoke that from userland, so the shim fakes
 * it for keys matching {@see self::$failKeySubstring}. Matching is by
 * substring so a test can fail entry writes (`<prefix>entry:`) while leaving
 * the lock writes (`<prefix>lock:`) working.
 */
final class ApcuStoreFailure
{
    public static ?string $failKeySubstring = null;

    public static function shouldFail(string $key): bool
    {
        return self::$failKeySubstring !== null && str_contains($key, self::$failKeySubstring);
    }

    public static function reset(): void
    {
        self::$failKeySubstring = null;
    }
}
