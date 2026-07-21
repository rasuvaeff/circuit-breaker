<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

use Rasuvaeff\Duration\Duration;

/**
 * Failure threshold expressed as "N failures out of the last M calls, within
 * a sliding time window". Backs the ring buffer a `Storage` keeps for the
 * `Closed` state: at most `window` outcomes are retained, and any outcome
 * older than `within` is evicted before the ratio is evaluated.
 *
 * @api
 */
final readonly class Ratio
{
    public function __construct(
        private int $failures,
        private int $window,
        private Duration $within,
    ) {
        if ($failures < 1) {
            throw new \InvalidArgumentException('Failures must be greater than or equal to 1');
        }
        if ($window < $failures) {
            throw new \InvalidArgumentException('Window must be greater than or equal to failures');
        }
        if ($within->isZero()) {
            throw new \InvalidArgumentException('Within must be greater than zero');
        }
    }

    public static function of(int $failures, int $window, Duration $within): self
    {
        return new self(failures: $failures, window: $window, within: $within);
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function window(): int
    {
        return $this->window;
    }

    public function within(): Duration
    {
        return $this->within;
    }
}
