<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support\StateMachine;

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;

/**
 * The system under test: one storage, one key, one config — and the clock the
 * commands move, because {@see InMemoryStorage} takes the current time as an
 * argument rather than reading it.
 */
final class BreakerHarness
{
    public const string KEY = 'stateful::breaker';

    public function __construct(
        public readonly InMemoryStorage $storage,
        public readonly BreakerConfig $config,
        public \DateTimeImmutable $now,
    ) {}
}
