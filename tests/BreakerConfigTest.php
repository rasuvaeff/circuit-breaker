<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(BreakerConfig::class)]
final class BreakerConfigTest
{
    public function exposesConstructorValues(): void
    {
        $config = new BreakerConfig(
            name: 'stripe',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 3,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: 2,
        );

        Assert::same($config->name(), 'stripe');
        Assert::same($config->failureThreshold()->failures(), 5);
        Assert::true($config->cooldown()->equals(Duration::seconds(30)));
        Assert::same($config->successThreshold(), 3);
        Assert::same($config->probeLimit(), 2);
    }

    public function defaultsProbeLimitToOne(): void
    {
        $config = $this->config();

        Assert::same($config->probeLimit(), 1);
        Assert::true($config->probeTimeout()->equals(Duration::seconds(30)));
    }

    public function acceptsExplicitProbeTimeout(): void
    {
        $config = new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            probeTimeout: Duration::seconds(10),
        );

        Assert::true($config->probeTimeout()->equals(Duration::seconds(10)));
    }

    public function explicitBroadClassifierTreatsAnyThrowableAsFailure(): void
    {
        $config = $this->config();

        Assert::true($config->isFailure(new \RuntimeException()));
        Assert::true($config->isFailure(new \InvalidArgumentException()));
    }

    public function customIsFailureClassifiesExceptions(): void
    {
        $config = new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => $e instanceof \RuntimeException,
        );

        Assert::true($config->isFailure(new \RuntimeException()));
        Assert::false($config->isFailure(new \InvalidArgumentException()));
    }

    public function normalResultsDefaultToSuccess(): void
    {
        Assert::same($this->config()->classifyResult('ok'), Outcome::Success);
    }

    public function normalResultsCanBeClassified(): void
    {
        $config = new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            classifyResult: static fn(mixed $result): Outcome => $result === 'degraded'
                ? Outcome::Failure
                : Outcome::Ignored,
        );

        Assert::same($config->classifyResult('degraded'), Outcome::Failure);
        Assert::same($config->classifyResult('cached'), Outcome::Ignored);
    }

    public function rejectsEmptyName(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid breaker name');

        new BreakerConfig(
            name: '',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    public function rejectsNameWithTrailingNewline(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid breaker name');

        new BreakerConfig(
            name: "api-breaker\n",
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    public function rejectsNameWithDisallowedCharacters(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid breaker name');

        new BreakerConfig(
            name: 'has space',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    public function rejectsZeroCooldown(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Cooldown');

        new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::zero(),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    public function rejectsSuccessThresholdBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Success threshold');

        new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 0,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }

    public function rejectsProbeLimitBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Probe limit');

        new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            probeLimit: 0,
        );
    }

    public function rejectsZeroProbeTimeout(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Probe timeout');

        new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(1)),
            cooldown: Duration::seconds(1),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => true,
            probeTimeout: Duration::zero(),
        );
    }

    private function config(): BreakerConfig
    {
        return new BreakerConfig(
            name: 'svc',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 3,
            isFailure: static fn(\Throwable $e): bool => true,
        );
    }
}
