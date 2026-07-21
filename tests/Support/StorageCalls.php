<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Tests\Support;

use Rasuvaeff\CircuitBreaker\Admission;
use Rasuvaeff\CircuitBreaker\AdmissionResult;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\OutcomeResult;
use Rasuvaeff\CircuitBreaker\Storage;

/**
 * Test-side sugar over the {@see Storage} fencing triple.
 *
 * `admit()`/`recordOutcome()` require `$admission`, `$admittedAt`, and
 * `$attemptId` — deliberately, so nobody can defeat probe fencing by omission.
 * Scenarios that do not exercise fencing still have to pass something; these
 * helpers mint a FRESH attempt id per call rather than remembering the last
 * one, so a probe outcome that forgets to thread its id back reads as stale
 * and fails the assertion loudly instead of quietly passing.
 */
trait StorageCalls
{
    private int $attemptSeq = 0;

    private function admitOn(
        Storage $storage,
        string $key,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        ?string $attempt = null,
    ): AdmissionResult {
        return $storage->admit($key, $config, $now, $attempt ?? $this->nextAttempt());
    }

    private function recordOn(
        Storage $storage,
        string $key,
        Outcome $outcome,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        Admission $admission = Admission::Allowed,
        ?\DateTimeImmutable $admittedAt = null,
        ?string $attempt = null,
    ): OutcomeResult {
        return $storage->recordOutcome(
            $key,
            $outcome,
            $config,
            $now,
            $admission,
            $admittedAt ?? $now,
            $attempt ?? $this->nextAttempt(),
        );
    }

    private function nextAttempt(): string
    {
        return 'attempt-' . ++$this->attemptSeq;
    }
}
