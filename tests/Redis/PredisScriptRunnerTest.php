<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Redis;

use Predis\ClientInterface;
use Predis\Command\RawCommand;
use Predis\Response\ServerException;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(PredisScriptRunner::class)]
final class PredisScriptRunnerTest
{
    public function executesScriptViaEvalshaFirst(): void
    {
        $client = $this->client(reply: 'allowed');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['cb:svc'], [1000, 30000, 1]);

        Assert::same($result, 'allowed');
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'cb:svc', 1000, 30000, 1]],
        ]);
    }

    public function executesScriptWithMultipleKeys(): void
    {
        $client = $this->client(reply: 'ok');
        $runner = new PredisScriptRunner($client);

        $runner->run('return 1', ['cb:svc', 'cb:svc:ring'], [1000]);

        Assert::same($this->executedCommands($client)[0], ['EVALSHA', [sha1('return 1'), 2, 'cb:svc', 'cb:svc:ring', 1000]]);
    }

    public function fallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->client(reply: 'allowed', evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['cb:svc'], []);

        Assert::same($result, 'allowed');
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'cb:svc']],
            ['EVAL', ['return 1', 1, 'cb:svc']],
        ]);
    }
    public function rethrowsNonNoscriptServerErrors(): void
    {
        $client = $this->client(reply: 'allowed', evalshaError: 'ERR Error compiling script');
        $runner = new PredisScriptRunner($client);

        Expect::exception(ServerException::class)->withMessageContaining('Error compiling script');

        $runner->run('return 1', ['cb:svc'], []);
    }

    private function client(mixed $reply, ?string $evalshaError = null): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->createCommand(Arg::any(), Arg::any()))
            ->answers(static fn(Invocation $call): RawCommand => new RawCommand((string) $call->args[0], (array) $call->args[1]));
        $evalsha = fn() => $client->executeCommand(Arg::which('getId', 'EVALSHA'));
        $eval = fn() => $client->executeCommand(Arg::which('getId', 'EVAL'));

        if ($evalshaError === null) {
            when($evalsha)->returns($reply);
        } else {
            when($evalsha)->throws(new ServerException($evalshaError));
        }
        when($eval)->returns($reply);

        return $client;
    }

    /** @return list<array{string, array}> */
    private function executedCommands(ClientInterface $client): array
    {
        return array_map(
            static fn(Invocation $call): array => [(string) $call->args[0]->getId(), $call->args[0]->getArguments()],
            Understudy::calls(fn() => $client->executeCommand(Arg::any())),
        );
    }
}
