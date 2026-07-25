# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
