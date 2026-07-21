<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Storage operation names used when wrapping infrastructure failures.
 *
 * @api
 */
enum StorageOperation: string
{
    case Admit = 'admit';
    case RecordOutcome = 'recordOutcome';
    case Snapshot = 'snapshot';
    case ForceState = 'forceState';
}
