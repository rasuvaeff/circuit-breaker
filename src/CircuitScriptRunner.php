<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Typed seam over a Redis server-side script call.
 *
 * Isolates the untyped Redis-client boundary (predis magic `__call`,
 * phpredis) from {@see RedisStorage}, which stays fully typed. Implement this
 * to back the store with a different client.
 *
 * @api
 */
interface CircuitScriptRunner
{
    /**
     * Evaluate a Lua script addressing `$keys` and return its raw reply.
     *
     * @param list<string>      $keys
     * @param list<int|string>  $args
     */
    public function run(string $script, array $keys, array $args): mixed;
}
