<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Redis;

use Predis\ClientInterface;
use Predis\Response\ServerException;
use Rasuvaeff\CircuitBreaker\CircuitScriptRunner;

/**
 * predis-backed {@see CircuitScriptRunner}.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every call; falls back to EVAL once per script cache
 * miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PredisScriptRunner implements CircuitScriptRunner
{
    public function __construct(
        private ClientInterface $client,
    ) {}

    #[\Override]
    public function run(string $script, array $keys, array $args): mixed
    {
        // createCommand/executeCommand are real ClientInterface methods; the
        // magic eval()/evalsha() @method annotations are not resolvable by
        // psalm across every supported predis release.
        try {
            return $this->client->executeCommand(
                $this->client->createCommand('EVALSHA', [sha1($script), count($keys), ...$keys, ...$args]),
            );
        } catch (ServerException $e) {
            if ($e->getErrorType() !== 'NOSCRIPT') {
                throw $e;
            }

            return $this->client->executeCommand(
                $this->client->createCommand('EVAL', [$script, count($keys), ...$keys, ...$args]),
            );
        }
    }
}
