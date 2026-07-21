<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Integration;

use Predis\Client;
use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Forks real processes that all race to occupy the same `HalfOpen` probe
 * slots against a real Redis, instead of asserting on a single sequential
 * process — the same rationale as `ApcuIntegrationTest`'s fork-stress test:
 * a single process cannot distinguish a correct atomic Lua transition from
 * one whose check-then-act ordering was subtly broken, because sequential
 * calls never actually interleave.
 */
#[Test]
#[CoversNothing]
final class RedisConcurrencyTest
{
    private const string NAME = 'concurrency-it';

    private ?Client $client = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $port = getenv('REDIS_PORT');
        $this->client = new Client([
            'host' => $host,
            'port' => $port === false || $port === '' ? 6379 : (int) $port,
        ]);
        $this->client->flushdb();
    }

    public function neverAdmitsMoreProbesThanProbeLimitUnderRealForkContention(): void
    {
        if ($this->client === null || !extension_loaded('pcntl')) {
            return;
        }

        $probeLimit = 3;
        $workers = 20;
        $config = new BreakerConfig(
            name: self::NAME,
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: $probeLimit,
        );
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $storage = new RedisStorage(new PredisScriptRunner($this->client));
        $storage->forceState(self::NAME, CircuitState::HalfOpen, $now);

        $resultFile = tempnam(sys_get_temp_dir(), 'cb-redis-fork-stress-');
        Assert::true($resultFile !== false);

        $this->client->set('test-barrier:ready', 0);
        $this->client->set('test-barrier:go', 0);

        $host = (string) getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $portInt = $port === false || $port === '' ? 6379 : (int) $port;

        $pids = [];
        for ($i = 0; $i < $workers; ++$i) {
            $pid = pcntl_fork();
            Assert::true($pid !== -1);

            if ($pid === 0) {
                $workerClient = new Client(['host' => $host, 'port' => $portInt]);
                $workerStorage = new RedisStorage(new PredisScriptRunner($workerClient));

                $workerClient->incr('test-barrier:ready');
                while ((int) $workerClient->get('test-barrier:go') !== 1) {
                    usleep(200);
                }

                $admission = $workerStorage->admit(
                    self::NAME,
                    $config,
                    $now,
                    sprintf('worker-%d', $i),
                )->admission();
                if ($admission === Admission::Probe) {
                    file_put_contents($resultFile, "1\n", \FILE_APPEND | \LOCK_EX);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        while ((int) $this->client->get('test-barrier:ready') < $workers) {
            usleep(200);
        }
        $this->client->set('test-barrier:go', 1);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $admitted = substr_count((string) file_get_contents($resultFile), "1\n");
        unlink($resultFile);

        Assert::true($admitted <= $probeLimit);
    }
}
