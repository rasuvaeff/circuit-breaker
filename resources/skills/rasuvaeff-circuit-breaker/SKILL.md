---
name: rasuvaeff-circuit-breaker
description: >-
  Protect downstream calls with rasuvaeff/circuit-breaker — CircuitBreaker,
  BreakerConfig, Ratio, InMemoryStorage, ApcuStorage, RedisStorage. A
  Closed → Open → HalfOpen state machine that fails fast during an outage and
  probes recovery with a bounded number of leased probes. Use when writing,
  reviewing or debugging circuit-breaker / fail-fast / fallback logic in a
  project that has this package installed.
---

# rasuvaeff/circuit-breaker

`CircuitBreaker::call()` guards a callback behind a `Closed → Open → HalfOpen`
state machine backed by a shared `Storage`. Namespace `Rasuvaeff\CircuitBreaker`.

## Safety rules — verify these on every change

1. **`isFailure` is required and must classify only downstream failures**
   (network errors, 5xx). An exception it returns `false` for is `Ignored`:
   rethrown as-is, never counted, never triggers `fallback`.

   ```php
   isFailure: static fn(\Throwable $e): bool => $e instanceof ClientExceptionInterface, // correct
   isFailure: static fn(\Throwable $e): bool => true, // wrong: validation errors open the circuit
   ```

2. **Pick storage by process topology.** `InMemoryStorage` is per-process —
   under PHP-FPM every worker gets its own circuit, so an outage is never
   detected collectively. Use `ApcuStorage` (one host) or `RedisStorage`
   (multiple hosts) whenever more than one worker serves traffic.

   | Storage | Shared across | Needs |
   |---|---|---|
   | `InMemoryStorage` | one process (tests/CLI) | nothing |
   | `ApcuStorage` | workers on one host | `ext-apcu` |
   | `RedisStorage` | all hosts | predis `^2.2` or `ext-redis` |

3. **`fallback` must not swallow the reason.** It receives the cause
   (`CircuitOpenException` on rejection, or the classified downstream
   exception) — log/propagate it, don't return a degraded value silently.
   `StorageFailure` (infrastructure outage) is never classified and never
   routed to `fallback`.

4. **HalfOpen probes are bounded.** `probeLimit` (default 1) caps concurrent
   probes, `successThreshold` consecutive successes close the circuit, any
   probe failure reopens immediately and restarts the cooldown. Don't "retry
   inside HalfOpen" — compose with `rasuvaeff/retry` around `call()` instead.

5. **Custom `Storage` backends: `admit()`/`recordOutcome()` must each be ONE
   atomic operation** (not `get`+`set` sequences — that reopens a
   check-then-act race), and the fencing triple (`$admission`, `$admittedAt`,
   `$attemptId`) must be passed back exactly as `admit()` returned it.

6. **`state()`/`metrics()`/`snapshot()` never mutate** — they do not apply the
   lazy `Open → HalfOpen` cooldown transition. Only `call()` advances state,
   so a long-idle breaker can look stale until the next `call()`.

## Canonical usage

```php
$cb = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'stripe',
        failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 3,
        probeLimit: 1,
        isFailure: static fn(\Throwable $e): bool => $e instanceof ClientExceptionInterface,
    ),
    storage: new InMemoryStorage(), // or ApcuStorage / RedisStorage
    clock: new SystemClock(),
);

$result = $cb->call(
    callback: static fn(): mixed => callDownstream(),
    fallback: static fn(\Throwable $e): mixed => degradedResult(),
);
```

## Full API

The complete reference — `BreakerConfig` options, the `Storage` contract,
Redis script runners, `Metrics`, `CircuitObserver` — ships with the package:
read `vendor/rasuvaeff/circuit-breaker/llms.txt` before guessing a method name.
