# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.0 — 2026-08-21

- Fix the Redis Closed-window ring: equal-score ZSET members sort lexicographically, and count-eviction removes the lexicographically smallest, so during a same-millisecond outcome burst a double-digit seq member ("10:f" < "7:s") evicted itself at insertion instead of the oldest entry — a hot breaker (more than `window` outcomes per millisecond) could fail to open at all while the downstream was failing. The seq is now zero-padded to fixed width, restoring insertion-order eviction. Parity scenarios added to both `InMemoryStorageTest` and `RedisIntegrationTest` (golden rule 3).
- Preserve the downstream exception when storage fails while recording its outcome: `StorageFailure` now carries a public readonly `downstreamOutcome` property with the exception the callback threw (previously it was lost entirely — unreachable through the chain). `null` when the callback succeeded or had not run.
- Report `openedAt` as epoch 0 for a never-opened breaker consistently across all backends (`InMemoryStorage`/`ApcuStorage` previously used the system clock for an unknown key — the only non-injected time source in the package).
- Docs: fix the breaker-name pattern shown in README.md/README.ru.md/llms.txt to the `\z` anchor the code actually uses (`$` matches before a trailing newline); add a clock-skew caveat for `canCall()`/`retryAfter` under `useServerTime: true`.

## 1.1.3 — 2026-08-21

- Document that `probeLimit` bounds admitted/leased `HalfOpen` slots, not concurrent execution against the downstream: because the probe lease is generation-wide rather than per-probe, a `probeTimeout` shorter than real downstream latency lets an expired lease reclaim all slots at once and admit fresh probes on top of ones still genuinely running. No behavior change — this clarifies `BreakerConfig::$probeLimit`, `Storage::admit()`, README.md, README.ru.md, and llms.txt to match the actual (and always-intended) guarantee, and points at pairing with `rasuvaeff/bulkhead` for a hard concurrency cap.
- Adopt `rasuvaeff/rector-named-literals` and apply the named-argument rule to literal calls.
- Raise `rasuvaeff/property-testing-testo` to `^0.6`.

## 1.1.2 — 2026-07-25

- Reject trailing newlines in breaker-name validation: anchor
  `BreakerConfig::NAME_PATTERN` with `\z` instead of `$` (PCRE `$` matches
  before a trailing `\n`, which let `"<name>\n"` pass and become the storage
  namespace/key).

## 1.1.1 — 2026-07-25

- Accidental empty release (tag pointed at a pre-fix commit). Superseded by
  1.1.2, which carries the actual fix.

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-circuit-breaker/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.

## 1.0.0 — 2026-07-21

- Initial release: a circuit breaker resilience primitive. `CircuitBreaker::call()`
  protects a callback behind a `Closed → Open → HalfOpen` state machine —
  `Closed` tracks failures in a ring buffer bounded by both count and time
  (`Ratio`), `Open` fails fast with `CircuitOpenException` or a `fallback`
  without touching the downstream, `HalfOpen` admits a bounded number of leased
  probes. Exception classification is mandatory (`BreakerConfig::$isFailure`)
  and normal return values can be classified too (`$classifyResult`); storage
  outages surface as `StorageFailure` and are never mistaken for downstream
  failures. Three backends implement one atomic `Storage` contract:
  `InMemoryStorage` (single process), `ApcuStorage` (single host, `apcu_add`
  lease), and `RedisStorage` (multi-host, one Lua script per method, Cluster
  ready, client-agnostic through `CircuitScriptRunner` with predis and
  `ext-redis` runners). Probes are fenced by an opaque attempt id, so an
  outcome from a reclaimed probe can never affect a newer generation.
  Committed transitions are observable through `CircuitObserver` /
  `CircuitTransition`, and `Metrics` exposes a snapshot for dashboards.
  Durations come from `rasuvaeff/duration`, time from a PSR-20 clock.
