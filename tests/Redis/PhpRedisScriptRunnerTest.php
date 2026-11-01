<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Redis;

use Rasuvaeff\CircuitBreaker\Redis\PhpRedisScriptRunner;
use Rasuvaeff\CircuitBreaker\Tests\Support\ScriptedRedis;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PhpRedisScriptRunner::class)]
final class PhpRedisScriptRunnerTest
{
    public function failedScriptSurfacesTheRedisErrorAndClearsIt(): void
    {
        if (!class_exists(\Redis::class)) {
            return;
        }

        $client = new ScriptedRedis(reply: false, errorAfterRun: 'ERR boom');

        $caught = null;

        try {
            (new PhpRedisScriptRunner($client))->run('return 1', ['k'], ['a']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, \RuntimeException::class);
        Assert::string($caught->getMessage())->contains('Redis script failed: ERR boom');
        // The sticky error must not leak into the next command on this client.
        Assert::null($client->getLastError());
    }

    public function staleErrorIsClearedBeforeTheScriptRuns(): void
    {
        if (!class_exists(\Redis::class)) {
            return;
        }

        $client = new ScriptedRedis(reply: 'ok', staleError: 'NOSCRIPT left over from an earlier command');

        $reply = (new PhpRedisScriptRunner($client))->run('return 1', ['k'], ['a']);

        Assert::same($reply, 'ok');
        Assert::same($client->calls, ['clearLastError', 'evalsha']);
    }
}
