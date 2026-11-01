<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support;

/**
 * Scripted stand-in for the phpredis client: `\Redis` is an internal class the
 * test-double engine refuses to subclass, so the few methods
 * {@see \Rasuvaeff\CircuitBreaker\Redis\PhpRedisScriptRunner} calls are
 * overridden by hand. Load it only after `class_exists(\Redis::class)`.
 */
final class ScriptedRedis extends \Redis
{
    /** @var list<string> */
    public array $calls = [];

    private ?string $error;

    public function __construct(
        private readonly mixed $reply,
        private readonly ?string $errorAfterRun = null,
        ?string $staleError = null,
    ) {
        $this->error = $staleError;
    }

    #[\Override]
    public function clearLastError(): bool
    {
        $this->calls[] = 'clearLastError';
        $this->error = null;

        return true;
    }

    #[\Override]
    public function evalsha(string $script_sha, array $args = [], int $num_keys = 0): mixed
    {
        $this->calls[] = 'evalsha';
        $this->error = $this->errorAfterRun;

        return $this->reply;
    }

    #[\Override]
    public function getLastError(): ?string
    {
        return $this->error;
    }
}
