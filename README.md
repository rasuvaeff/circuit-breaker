# rasuvaeff/circuit-breaker

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/circuit-breaker/v)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/circuit-breaker/downloads)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![Build](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/circuit-breaker/php)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Circuit breaker resilience primitive: protects a downstream dependency from
cascading failure. `Closed → Open → HalfOpen` state, switched by
sliding-window success/failure counts. In `Open`, calls fail fast — no
network round-trip — until a cooldown elapses; a bounded number of `HalfOpen`
probes then decide whether to resume or reopen.

Pluggable distributed state via `Storage`: in-memory (tests/CLI), APCu
(single host), Redis (multi-host cluster) — all three implement the exact
same atomic `admit()`/`recordOutcome()` contract, so switching backends never
changes behavior, only how far the shared state reaches.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) for the typed cooldown/window values
- `psr/clock` — inject any `ClockInterface`; `Clock\SystemClock` (production) and
  `Clock\FakeClock` (tests) are included
- For multi-host state (`RedisStorage`): a reachable Redis server plus **one**
  Redis client — [`predis/predis`](https://github.com/predis/predis) ^2.2
  (pure-PHP, `PredisScriptRunner`) or `ext-redis` (`PhpRedisScriptRunner`).
  Both are optional; install the one you use.
- `ext-apcu` for single-host state (`ApcuStorage`) — optional, not a hard dependency

## Installation

```bash
composer require rasuvaeff/circuit-breaker

# for RedisStorage with the pure-PHP client:
composer require predis/predis
```

## Usage

```php
use Psr\Http\Client\ClientExceptionInterface;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;

$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'stripe',
        failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),   // how long Open lasts before a probe is allowed
        successThreshold: 3,               // consecutive probe successes to close again
        // Classify only exceptions that indicate a downstream failure.
        isFailure: static fn(\Throwable $e): bool => $e instanceof ClientExceptionInterface,
    ),
    storage: new InMemoryStorage(), // or ApcuStorage / RedisStorage - same contract
    clock: new SystemClock(),
);

$charge = $cb->call(
    callback: static fn(): mixed => $stripe->charges->create([/* ... */]),
    fallback: static fn(\Throwable $e): mixed => ChargeResult::queuedForRetry(),
);

if ($cb->state()->value === 'open') {
    // show a degraded UI without attempting the call
}
```

With Redis (multi-host):

```php
use Predis\Client;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;

$storage = new RedisStorage(new PredisScriptRunner(new Client(['host' => '127.0.0.1'])));
```

With `ext-redis` instead of predis:

```php
use Rasuvaeff\CircuitBreaker\Redis\PhpRedisScriptRunner;

$redis = new \Redis();
$redis->connect('127.0.0.1');
$storage = new RedisStorage(new PhpRedisScriptRunner($redis));
```

With APCu (single host):

```php
use Rasuvaeff\CircuitBreaker\ApcuStorage;

$storage = new ApcuStorage();
```

### Public API

| Type | Description |
|---|---|
| `CircuitBreaker` | `call(callable, ?callable $fallback): mixed`, `canCall()`, `state()`, `metrics()`, `forceOpen()`, `forceClosed()` |
| `BreakerConfig` | `name`, `failureThreshold` (`Ratio`), `cooldown`, `successThreshold`, required `isFailure`, `probeLimit`, `probeTimeout` |
| `Ratio` | "N failures out of the last M calls, within a sliding window" — backs `failureThreshold` |
| `CircuitState` | Enum: `Closed`, `Open`, `HalfOpen` |
| `Outcome` | Enum: `Success`, `Failure`, `Ignored` — result of `isFailure()` classification |
| `Admission` | Enum: `Allowed`, `Probe`, `Rejected` — `Storage::admit()`'s decision |
| `Storage` | Interface: `admit`, `recordOutcome`, `snapshot`, `forceState` — the distributed-state seam. `admit()`/`recordOutcome()` require the fencing triple (`admission`, `admittedAt`, `attemptId`) |
| `StorageFailure` | Infrastructure exception for storage outages; exposes `operation`, `breakerName`, and the original exception |
| `InMemoryStorage` | Single-process store (tests/CLI); no cross-process coordination |
| `ApcuStorage` | Single-host cross-process store; `apcu_add` lock (lease, `lockTtlSeconds`) around the whole read-transition-write |
| `RedisStorage` | Multi-host cross-process store; one Lua script per `Storage` method |
| `CircuitScriptRunner` | Typed seam over a Redis script call (implement for another client) |
| `Redis\PredisScriptRunner` | predis-backed `CircuitScriptRunner`; EVALSHA with EVAL fallback |
| `Redis\PhpRedisScriptRunner` | `ext-redis`-backed `CircuitScriptRunner`; EVALSHA with EVAL fallback |
| `StateRecord` | Snapshot returned by `Storage`: `state`, `openedAt`, `successes`, `failures`, `rejected` |
| `Metrics` | Observability snapshot from `CircuitBreaker::metrics()`, mirrors `StateRecord` |
| `CircuitTransition` | A committed state change: `breakerName`, `from`, `to`, `occurredAt`, `reason`, `state` |
| `TransitionReason` | Enum: why a transition happened — `FailureThresholdReached`, `CooldownElapsed`, `ProbeSucceeded`, `ProbeFailed`, `ForcedOpen`, `ForcedClosed`, `ForcedHalfOpen` |
| `CircuitObserver` | Interface receiving committed `CircuitTransition` events; paired with an error handler |
| `CircuitOpenException` | Thrown (or passed to `fallback`) when a call is rejected; carries `breakerName`, `retryAfter` |
| `Clock\SystemClock` | `Psr\Clock\ClockInterface` using the wall clock |
| `Clock\FakeClock` | Controllable clock for testing cooldown/window expiry |

### How `call()` decides

1. `Storage::admit()` — atomically applies the `Open → HalfOpen` cooldown
   transition, then decides `Allowed` (`Closed`) / `Probe` (`HalfOpen`, slot
   occupied) / `Rejected` (`Open`, or `HalfOpen` with no free probe slot).
2. `Rejected` → `$callback` is **never invoked**. `$fallback`, if given,
   receives a fresh `CircuitOpenException`; otherwise it is thrown. In `Open`,
   `retryAfter` is the cooldown deadline; for a saturated `HalfOpen`, it is a
   conservative deadline based on `probeTimeout`.
3. `Allowed`/`Probe` → `$callback` runs. Its outcome is classified via
   `isFailure()` into `Success` / `Failure` / `Ignored`, recorded with
   exactly one `Storage::recordOutcome()` call (this is also what releases a
   `HalfOpen` probe slot — including when `$callback` throws). The outcome is
   timestamped when the callback completes. The original `Admission` and an
   opaque attempt ID are passed so a reclaimed probe cannot affect a newer
   generation or enter a newly reset `Closed` window.
4. A `Failure` outcome with `$fallback` given returns the fallback's result;
   otherwise the original exception is rethrown. An `Ignored` outcome
   (`isFailure() === false`) is **always** rethrown as-is — it never triggers
   `$fallback` and never counts against the threshold.

### Sizing the knobs

- **`failureThreshold`** (`Ratio::of(failures, window, within)`) — a sliding
  ring buffer capped at `window` entries and pruned to the last `within`
  duration. `Ratio::of(failures: 5, window: 10, within: seconds(60))` opens
  once 5 of the last (up to 10, within 60s) `Closed` calls failed.
- **`cooldown`** — how long `Open` lasts before the first probe is allowed.
  Too short and probes hammer a still-recovering dependency; too long and
  recovery is delayed after the dependency is actually healthy again.
- **`successThreshold`** — consecutive `HalfOpen` probe successes needed to
  close. `1` closes on the first successful probe; higher values demand
  sustained recovery before resuming full traffic.
- **`probeLimit`** — concurrent probes admitted in `HalfOpen` (default `1`).
  `1` is the safest default (single canary call); raising it to 3-5 improves
  recovery throughput at the cost of sending more traffic to a dependency
  that might still be unhealthy.
- **`probeTimeout`** — maximum lifetime of an admitted HalfOpen probe lease;
  defaults to `cooldown`. Abandoned slots are reclaimed automatically.
- **`isFailure`** — required classifier. Filter to exceptions that actually
  indicate the *downstream* is unhealthy (network errors, 5xx responses).

### How the state holds across workers/hosts

`RedisStorage` runs `admit()`/`recordOutcome()`/`snapshot()`/`forceState()`
each as exactly one Lua script. Redis executes scripts to completion without
interleaving another client's command, so the read-evaluate-transition
sequence (check counters → decide → write new state) is atomic across every
process racing on the same breaker — splitting that into separate
`INCR`/`GET`/`SET` calls would reopen the exact check-then-act race the
package exists to prevent. State lives in one Redis hash (`state`,
`openedAt`, probe counters), one sorted set per breaker (the `Closed` sliding
window, member = outcome, score = timestamp), and a set of opaque IDs for
active probes. All keys share a Redis Cluster hash tag, so every multi-key
script stays within one cluster slot.

`ApcuStorage` approximates that with an `apcu_add`-based lock around a
read-transition-write of the whole entry (APCu has no server-side scripting).
Locks carry integer owner tokens and release through CAS, so a stale owner
cannot release a replacement lock after its lease expired. The lock is a
**lease** (`lockTtlSeconds`, default 1s), not a Redis-grade mutex: a critical
section that outlives its lease could otherwise commit over a worker that
already took over, so the commit verifies ownership first and raises
`StorageFailure` instead. That narrows the window rather than closing it —
APCu cannot make the ownership check and the entry write one atomic step, so
raise `lockTtlSeconds` if your workers can stall for longer. A rejected
`apcu_store()` (shared memory exhausted) raises `StorageFailure` too: a
transition that was not written is never reported as committed. APCu only
coordinates workers on the **same host** — use `RedisStorage` for a pool
spread across hosts.

## Security

- `name` is validated against `/^[A-Za-z0-9_.:-]+$/` and becomes part of the
  Redis/APCu key — untrusted names are rejected, not interpolated blindly.
- Values flow into the Lua scripts as bound `ARGV`/`KEYS`, never
  string-concatenated.
- Every `CircuitBreaker::call()` carries an opaque attempt ID. Redis records
  active probe IDs and atomically ignores outcomes from reclaimed generations.
- The package opens no network connections itself; you supply the Redis client.

## Caveats

- **`isFailure` is required.** Pass a classifier that inspects the exception
  type/status so caller bugs do not open the circuit.
- **Storage failures are not downstream failures.** An exception from
  `recordOutcome()` is wrapped in `StorageFailure`, is never passed through
  `isFailure`, and does not trigger `fallback`; the wrapper exposes the
  failed operation and the original exception via `getPrevious()`. See
  `examples/07-storage-outage.php` for a logging/degradation pattern.
- **Clocks and time mode.** `RedisStorage` uses Redis server time by default for
  cooldown and probe leases. Pass `useServerTime: false` only for deterministic
  tests; that mode compares the caller's clock and requires synchronization.
  APCu always uses the caller clock, so hosts using it must run NTP. Probe
  fencing does not depend on clock synchronization: Redis validates the opaque
  attempt ID against the active probe generation in both time modes.
- **`snapshot()` never mutates.** It does not apply the lazy `Open` →
  `HalfOpen` cooldown transition and does not prune the sliding window — only
  `call()` (via `admit()`/`recordOutcome()`) does. `state()`/`metrics()`
  therefore reflect the state as of the last real call, not a live
  re-evaluation against the current time.
- **`InMemoryStorage` is single-process only** — it does **not** coordinate
  across the FPM pool. Use it for tests and CLI tools.
- **`ApcuStorage` only coordinates workers on the same machine.** A pool
  spread across hosts needs `RedisStorage`. If lock contention exceeds the
  configured spin budget, the operation throws and `CircuitBreaker` exposes a
  `StorageFailure`; state transitions are never silently dropped.
- **This package does not retry or limit concurrency.** Compose with
  [`rasuvaeff/retry`](https://github.com/rasuvaeff/retry) (retries transient
  errors *inside* one call) and
  [`rasuvaeff/bulkhead`](https://github.com/rasuvaeff/bulkhead) (limits
  concurrent calls) — see `examples/03-with-retry.php` and
  `examples/04-with-bulkhead.php`.

## Result classification and transitions

`BreakerConfig::$classifyResult` is optional and defaults every normal callback
return to `Outcome::Success`. Configure it when an API reports downstream
failure in a normal value; the original value is still returned and fallback is
not invoked.

Pass a `CircuitObserver` and its paired error handler to `CircuitBreaker` to
receive committed `CircuitTransition` events without polling `metrics()`.
`Storage::admit()` returns `AdmissionResult`, and `recordOutcome()` returns
`OutcomeResult`; both carry optional transition metadata.

Implementing `Storage` yourself: `$admission`, `$admittedAt`, and `$attemptId`
are required on `recordOutcome()` and must be exactly what `admit()` returned
and received. An outcome that is not an `Admission::Probe` must never move a
`HalfOpen` breaker — it was admitted while the breaker was `Closed` and belongs
to no probe generation.

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| `01-in-memory.php` | Minimal breaker: tripping on `failureThreshold`, `fallback` | no |
| `02-redis-cluster.php` | Cross-host state via `RedisStorage` + predis | yes |
| `03-with-retry.php` | Composition with `rasuvaeff/retry` | no |
| `04-with-bulkhead.php` | Composition with `rasuvaeff/bulkhead` | no |
| `05-prometheus-metrics.php` | Exporting `Metrics` in Prometheus exposition format | no |
| `06-apcu.php` | Single-host cross-process state via `ApcuStorage` | no (needs `ext-apcu`) |
| `07-storage-outage.php` | Handling `StorageFailure` separately from downstream failures | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Integration tests need a Redis server (self-skip unless `REDIS_HOST` is set),
`ext-apcu` (self-skip via `ApcuStorage::isAvailable()`) and `ext-redis`
(self-skip via `extension_loaded('redis')`); the base `composer:2` image has
none of them, so run the suite in an image carrying `apcu`, `pcntl` and `redis`
(plus `apc.enable_cli=1`):

```bash
docker run -d --name cb-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  <php-image-with-apcu-pcntl-redis> vendor/bin/testo --suite=Integration
docker rm -f cb-redis
```

## License

[BSD-3-Clause](LICENSE.md)
