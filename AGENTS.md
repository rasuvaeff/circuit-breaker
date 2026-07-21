# AGENTS.md — circuit-breaker

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/circuit-breaker` is a circuit breaker resilience primitive
(namespace `Rasuvaeff\CircuitBreaker`). `CircuitBreaker::call()` protects a
callback behind a `Closed → Open → HalfOpen` state machine: `Closed` runs
calls and tracks failures in a sliding window; `Open` fails fast
(`CircuitOpenException`, or `fallback`) without invoking the callback;
`HalfOpen` admits a bounded number of probes after `cooldown` elapses and
either closes (enough consecutive probe successes) or reopens (any probe
failure). Three cross-process-capable backends implement one `Storage`
contract: `InMemoryStorage` (single-process, tests/CLI), `ApcuStorage`
(single-host, `apcu_add` spinlock), `RedisStorage` (multi-host, one Lua
script per `Storage` method, client-agnostic via `CircuitScriptRunner` —
`Redis\PredisScriptRunner` for predis, `Redis\PhpRedisScriptRunner` for
`ext-redis`, both EVALSHA-first). Thresholds/cooldown are `rasuvaeff/duration`
`Duration`s; time comes from an injected `Psr\Clock\ClockInterface`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **`admit()` and `recordOutcome()` MUST each be one atomic operation.** This
   is the entire reason the `Storage` interface looks the way it does — NOT a
   set of small atomic primitives (`incr`/`get`/`cas`) that the breaker
   composes. Splitting "read counters" from "decide and transition" reopens a
   check-then-act race: a second process can observe the pre-transition
   counters and make a conflicting decision before the first process commits.
   `InMemoryStorage` gets this for free (PHP-FPM/CLI runs one request per
   process, no preemptive threading); `ApcuStorage` gets it from an
   `apcu_add`-based lock around the whole read-transition-write — a LEASE
   (`lockTtlSeconds`), so its commit re-checks ownership and fails rather than
   overwriting a worker that took over; that narrows the race, it does not
   close it (APCu cannot check-and-write atomically);
   `RedisStorage` gets it from Redis executing a Lua script to completion
   without interleaving another client's command. All three PHP-side backends
   that hold the whole entry as one value (`InMemoryStorage`, `ApcuStorage`)
   share the exact same transition logic via
   `Internal\BreakerState` — never duplicate that logic; `RedisStorage`
   reimplements the same rules in Lua (`Redis\LuaScripts`) because a PHP
   function cannot run inside a Redis script, but the two must stay in sync
   (`InMemoryStorageTest`/`RedisIntegrationTest` cover the same scenarios by
   design — a Lua change without a matching `InMemoryStorageTest` scenario, or
   vice versa, is a smell).
   `CircuitBreaker` also passes one opaque `attemptId` through `admit()` and
   `recordOutcome()`; Redis uses it to fence reclaimed probes independently of
   clock synchronization. Never remove or reuse that ID across calls.
   The fencing triple (`admission`, `admittedAt`, `attemptId`) is REQUIRED on
   the interface — never reintroduce defaults "for convenience": optional
   fencing is exactly how a reclaimed probe's outcome reaches a newer
   generation. Tests get their sugar from `Tests\Support\StorageCalls`, which
   mints a FRESH id per call so a probe outcome that forgets to thread its id
   fails loudly instead of silently passing.
   Only an `Admission::Probe` outcome may move a `HalfOpen` breaker: an
   `Allowed` one was admitted while the breaker was `Closed` and belongs to no
   probe generation. This rule lives in BOTH `Internal\BreakerState` and
   `Redis\LuaScripts` — change them together.
4. **Preserve the public contract.** Update README + README.ru.md + llms.txt
   + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

`rasuvaeff/duration` and `rasuvaeff/property-testing` are normal Packagist
dependencies (`^1.0`/`^2.6`) — no path-repo, no monorepo-root mount needed.

### Integration & mutation need Redis + APCu

Integration tests (`tests/Integration`) are excluded from the Unit suite and
self-skip unless `REDIS_HOST` is set (Redis) / `ApcuStorage::isAvailable()` is
true (APCu), so `composer build` is green with neither. But `RedisStorage`/
`ApcuStorage` are **only** covered by the Integration suite (`#[Covers]` on
`RedisIntegrationTest`/`PhpRedisIntegrationTest`/`ApcuIntegrationTest` —
`#[CoversNothing]` would zero their mutation attribution entirely, not just
deprioritize it) — run mutation with both reachable, or MSI drops:

```bash
docker run -d --name cb-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/repo -w /repo/circuit-breaker -e REDIS_HOST=127.0.0.1 \
  --entrypoint sh <pcov+apcu-image> -c 'git config --global --add safe.directory "*"; composer mutation'
docker rm -f cb-redis
```

The pcov image needs `apcu` + `pcntl` + `redis` extensions and
`apc.enable_cli=1` (`pecl install pcov apcu redis`,
`docker-php-ext-install pcntl`, then an ini file with `apc.enable_cli=1` —
see `.github/workflows/build.yml`'s `coverage` job for the exact extension
list). `redis` backs `PhpRedisIntegrationTest`, which is the only coverage
`Redis\PhpRedisScriptRunner` gets. `pcntl` backs
`ApcuIntegrationTest::neverAdmitsMoreProbesThanProbeLimitUnderRealForkContention`
and `RedisConcurrencyTest::neverAdmitsMoreProbesThanProbeLimitUnderRealForkContention`,
which fork real processes behind a rendezvous barrier to race for the same
`HalfOpen` probe slots — a single-process test cannot distinguish a correct
atomic transition from one whose check-then-act ordering was subtly broken,
since sequential calls never actually interleave.

CI runs the Integration suite and mutation in the `coverage` job, which
provides a `redis:7-alpine` service container + `REDIS_HOST`, plus
`apcu`/`pcntl`/`redis`.

## Invariants & gotchas

- **`StateRecord.successes()`/`failures()` meaning depends on `state`**:
  `Closed`/`Open` — live counts of the `Closed`-window ring buffer (frozen
  once `Open`, since nothing touches it until the breaker re-enters
  `Closed`); `HalfOpen` — consecutive probe successes so far, `failures()`
  always `0` (a probe failure transitions out of `HalfOpen` immediately, so a
  nonzero failure count while still `HalfOpen` is unobservable by
  construction).
- **`rejected` resets on `Closed → Open`, keeps accumulating through
  `HalfOpen → Open`, resets again on `→ Closed`.** It measures "calls turned
  away during the current outage episode", not a lifetime total — a failed
  probe restarting the cooldown is the *same* episode, not a new one.
- **The `Closed` ring buffer is bounded by BOTH count (`window`) and time
  (`within`)** — `Ratio::of(failures, window, within)` means "N failures out
  of the last (up to `window`) calls, and any call older than `within` has
  already aged out". Both bounds are enforced on every `Closed` outcome, not
  just at read time.
- **`Outcome::Ignored` never touches the `Closed` window.** It is not "a
  success that dilutes the ratio" — it is excluded entirely, on purpose:
  `isFailure() === false` means the exception says nothing about the
  downstream's health (see golden rule in the README about the unsafe
  default `isFailure`).
- **A stale `HalfOpen` probe completing after a sibling probe already
  transitioned to `Open`**: `recordOutcome()` branches on the state observed
  at the START of that call — if it's `Open` by then, the call is a pure
  no-op (no probe-slot bookkeeping, no counter change). Any leaked probe-slot
  accounting from that race is wiped the next time `admit()` re-enters
  `HalfOpen` (fresh `probeSlots = 0` on every `Open → HalfOpen` transition) —
  don't try to reconcile it inside `recordOutcome()` itself, that's the wrong
  layer.
- **A stale `HalfOpen` probe completing after a sibling transitioned to
  `Closed`** keeps its original `Admission::Probe`. Its success/ignored result
  is a no-op; its failure reopens immediately and must never enter the fresh
  `Closed` ring. `CircuitBreaker` must pass the admission returned by `admit()`
  into exactly one `recordOutcome()` call.
- Outcome timestamps are captured after the callback completes. Keep storage
  calls outside the callback `try/catch`: a storage exception is infrastructure
  failure, not a downstream outcome, and must never be classified or retried as
  `Outcome::Failure`.
- `BreakerConfig::$isFailure` is required; callers must explicitly classify
  downstream exceptions. `probeTimeout` leases HalfOpen slots and reclaims
  abandoned probes. Redis uses server time by default; `useServerTime: false`
  is intended for deterministic tests only. Opaque attempt IDs provide strict
  Redis probe fencing in both modes. Storage outages, including APCu lock-budget
  exhaustion, surface as `StorageFailure`, never as callback outcomes or
  fallback results.
- `BreakerConfig::$classifyResult` classifies normal callback returns and
  defaults to `Outcome::Success`; a normal `Failure` updates state but returns
  the original value and never invokes fallback. `CircuitObserver` receives
  committed `CircuitTransition` events; observer and error handler are a pair.
- **`snapshot()` takes no `\DateTimeImmutable $now` and applies no
  auto-transition or pruning** — by design (see `Storage::snapshot()`'s
  docblock). A long-idle breaker's `Closed`/`Open` counts can look stale
  relative to the current time; only `admit()`/`recordOutcome()` (called from
  `call()`) advance state.
- **All Redis clients are optional deps.** `predis/predis` lives in
  `require-dev` + `suggest` (NOT `require`) — an APCu-only or in-memory
  consumer must not pull a Redis client; `ext-redis` is `suggest`-only
  likewise. `ext-apcu` is `suggest`-only (not `require`). Psalm resolves
  `apcu_*` signatures from its bundled `jetbrains/phpstorm-stubs` call map
  regardless of whether the extension is loaded; the `\Redis` class comes
  from psalm's bundled `redis.phpstub` via
  `<enableExtensions><extension name="redis"/></enableExtensions>` in
  `psalm.xml` — static analysis needs no extensions installed.
  `composer-require-checker.json` whitelists the `apcu_*` symbols plus
  `Predis\ClientInterface`, `Predis\Response\ServerException`, and `Redis`
  (no required package declares them). `property-testing` (dev) needs
  `ext-mbstring` → CI `extensions: json, mbstring`.
- **`rasuvaeff/retry` and `rasuvaeff/bulkhead` are composition partners, not
  dependencies** — do not add either to `require`/`require-dev`. The
  corresponding examples (`03-with-retry.php`, `04-with-bulkhead.php`)
  self-skip via `class_exists(...)` when the sibling package isn't installed,
  the same pattern `02-redis-cluster.php` uses for `REDIS_HOST`.
- Code: `declare(strict_types=1)`, `final readonly class` (or `final class`
  where mutable internal state is intrinsic, e.g. `InMemoryStorage`),
  `#[\Override]`, explicit types.
- `examples/` is part of the public contract: keep scripts runnable (self-skip
  scripts print a message and `exit(0)`, they don't fatal) and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags; updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/` — no
  `unpinned-uses`, `excessive-permissions`, or `artipacked` findings. (The
  `redis` service container image is digest-pinned, mirroring
  `rasuvaeff/bulkhead`.)

## When you finish

- Update `README.md`, `README.ru.md` (both languages, same commit), `llms.txt`
  (and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` (green with no Redis/APCu) **and** the Integration
  suite + mutation against a real Redis + APCu. Paste the output.
