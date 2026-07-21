<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests;

use Rasuvaeff\CircuitBreaker\ApcuStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ApcuStorage::class)]
final class ApcuStorageTest
{
    public function isAvailableReflectsTheExtension(): void
    {
        Assert::same(ApcuStorage::isAvailable(), extension_loaded('apcu') && apcu_enabled());
    }

    public function acceptsMinimalLockTuning(): void
    {
        Assert::instanceOf(new ApcuStorage(lockMaxAttempts: 1, lockRetryMicros: 1), ApcuStorage::class);
    }

    public function rejectsNonPositiveLockMaxAttempts(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lock max attempts must be greater than or equal to 1');

        new ApcuStorage(lockMaxAttempts: 0);
    }

    public function rejectsNonPositiveLockRetryMicros(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lock retry micros must be greater than or equal to 1');

        new ApcuStorage(lockRetryMicros: 0);
    }

    public function rejectsNonPositiveLockTtlSeconds(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lock TTL seconds must be greater than or equal to 1');

        new ApcuStorage(lockTtlSeconds: 0);
    }
}
