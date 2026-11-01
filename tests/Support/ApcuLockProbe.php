<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support;

/**
 * Recorder for the `apcu_add()` / `apcu_store()` / `usleep()` / `random_int()` shims in
 * `tests/Support/apcu_store_shim.php`. While disarmed the shims are pure
 * pass-through; a test arms the probe to count how many lock attempts and
 * back-off sleeps {@see \Rasuvaeff\CircuitBreaker\ApcuStorage::lock()} makes
 * and which bounds it draws its token from. The spin budget is otherwise
 * observable only through wall-clock time.
 */
final class ApcuLockProbe
{
    public static bool $armed = false;
    public static int $adds = 0;
    public static int $sleeps = 0;

    /** @var list<int> */
    public static array $sleepMicros = [];

    /** @var list<int> */
    public static array $tokenLowerBounds = [];

    /** @var array<string, int> TTL of the last store per key */
    public static array $storeTtls = [];

    public static function arm(): void
    {
        self::reset();
        self::$armed = true;
    }

    public static function reset(): void
    {
        self::$armed = false;
        self::$adds = 0;
        self::$sleeps = 0;
        self::$sleepMicros = [];
        self::$tokenLowerBounds = [];
        self::$storeTtls = [];
    }
}
