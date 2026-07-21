<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Integration;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PhpRedisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\CircuitBreaker\Tests\Support\StorageCalls;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RedisStorage::class)]
#[Covers(PhpRedisScriptRunner::class)]
final class PhpRedisIntegrationTest
{
    use StorageCalls;

    private const string NAME = 'it-phpredis';

    private RedisStorage $storage;
    private \Redis $client;
    private \DateTimeImmutable $base;

    #[BeforeTest]
    public function setUp(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $port = getenv('REDIS_PORT');
        $this->client = new \Redis();
        $this->client->connect($host, $port === false || $port === '' ? 6379 : (int) $port);
        $this->client->flushDB();
        $this->storage = new RedisStorage(new PhpRedisScriptRunner($this->client), useServerTime: false);
        $this->base = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }

    public function admitAndRecordOutcomeRoundTrip(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config();
        $this->admitOn($this->storage, self::NAME, $config, $this->base)->admission();
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Success, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Closed);
        Assert::same($record->successes(), 1);
    }

    public function opensAfterFailureThreshold(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $config = $this->config(failures: 1, window: 1);
        $record = $this->recordOn($this->storage, self::NAME, Outcome::Failure, $config, $this->base)->state();

        Assert::same($record->state(), CircuitState::Open);
    }

    public function scriptCacheFlushIsSurvivedViaEvalFallback(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $this->client->script('FLUSH');

        $admission = $this->admitOn($this->storage, self::NAME, $this->config(), $this->base)->admission();

        Assert::same($admission, Admission::Allowed);
    }

    /**
     * phpredis keeps `getLastError()` set until it is explicitly cleared. If
     * the runner reads that sticky value as its own result, a NOSCRIPT left
     * over from an EARLIER command sends the (mutating) script through the
     * EVAL fallback even though EVALSHA already ran it — executing it twice.
     * The counter must be incremented exactly once.
     */
    public function staleLastErrorDoesNotRunTheScriptTwice(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $script = "return redis.call('INCR', KEYS[1])";
        $runner = new PhpRedisScriptRunner($this->client);
        $this->client->del('exactly-once');

        // Prime the script cache so EVALSHA below succeeds...
        $this->client->script('LOAD', $script);
        // ...then leave a NOSCRIPT error behind from an unrelated command.
        $this->client->evalsha(sha1('return 1'), [], 0);
        Assert::string((string) $this->client->getLastError())->contains('NOSCRIPT');

        $runner->run($script, ['exactly-once'], []);

        Assert::same((int) $this->client->get('exactly-once'), 1);
    }

    private function config(int $failures = 5, int $window = 10): BreakerConfig
    {
        return new BreakerConfig(
            name: self::NAME,
            failureThreshold: Ratio::of(failures: $failures, window: $window, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
