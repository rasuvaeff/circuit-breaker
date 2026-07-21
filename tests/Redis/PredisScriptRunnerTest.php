<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Redis;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Command\RawCommand;
use Predis\Response\ServerException;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PredisScriptRunner::class)]
final class PredisScriptRunnerTest
{
    public function executesScriptViaEvalshaFirst(): void
    {
        $client = $this->fakeClient(reply: 'allowed');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['cb:svc'], [1000, 30000, 1]);

        Assert::same($result, 'allowed');
        Assert::same(count($client->calls), 1);
        Assert::same($client->calls[0], ['EVALSHA', [sha1('return 1'), 1, 'cb:svc', 1000, 30000, 1]]);
    }

    public function executesScriptWithMultipleKeys(): void
    {
        $client = $this->fakeClient(reply: 'ok');
        $runner = new PredisScriptRunner($client);

        $runner->run('return 1', ['cb:svc', 'cb:svc:ring'], [1000]);

        Assert::same($client->calls[0], ['EVALSHA', [sha1('return 1'), 2, 'cb:svc', 'cb:svc:ring', 1000]]);
    }

    public function fallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->fakeClient(reply: 'allowed', evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['cb:svc'], []);

        Assert::same($result, 'allowed');
        Assert::same(count($client->calls), 2);
        Assert::same($client->calls[0][0], 'EVALSHA');
        Assert::same($client->calls[1], ['EVAL', ['return 1', 1, 'cb:svc']]);
    }

    public function rethrowsNonNoscriptServerErrors(): void
    {
        $client = $this->fakeClient(reply: 'allowed', evalshaError: 'ERR Error compiling script');
        $runner = new PredisScriptRunner($client);

        Expect::exception(ServerException::class)->withMessageContaining('Error compiling script');

        $runner->run('return 1', ['cb:svc'], []);
    }

    /**
     * @return ClientInterface&object{calls: list<array{string, array}>}
     */
    private function fakeClient(mixed $reply, ?string $evalshaError = null): object
    {
        return new class ($reply, $evalshaError) implements ClientInterface {
            /** @var list<array{string, array}> */
            public array $calls = [];

            public function __construct(
                private readonly mixed $reply,
                private readonly ?string $evalshaError,
            ) {}

            #[\Override]
            public function __call($method, $arguments): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function getCommandFactory(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function getOptions(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function connect(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function disconnect(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function getConnection(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function createCommand($method, $arguments = []): CommandInterface
            {
                return new RawCommand((string) $method, (array) $arguments);
            }

            #[\Override]
            public function executeCommand($command): mixed
            {
                $this->calls[] = [strtoupper($command->getId()), $command->getArguments()];

                if ($command->getId() === 'EVALSHA' && $this->evalshaError !== null) {
                    throw new ServerException($this->evalshaError);
                }

                return $this->reply;
            }
        };
    }
}
