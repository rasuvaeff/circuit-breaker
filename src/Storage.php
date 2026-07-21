<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker;

/**
 * Atomic, composite operations over a breaker's distributed state.
 *
 * This is the critical seam of the package: the correctness of the breaker
 * in a cluster depends entirely on `admit()` and `recordOutcome()` each being
 * a single atomic operation — read state, evaluate thresholds, transition —
 * not a sequence of smaller atomic primitives. Splitting "read counters" from
 * "compare-and-set the new state" reopens a check-then-act race: a second
 * process can observe the pre-transition counters and make a conflicting
 * decision before the first process commits its transition. `InMemoryStorage`
 * enforces this with a single critical section; `RedisStorage` with one Lua
 * script per method (Redis is single-threaded, so a script always runs to
 * completion without interleaving).
 *
 * Implementations keep separate windows per state:
 *
 * - `Closed`: a ring buffer of the last `Ratio::window()` outcomes, pruned by
 *   `Ratio::within()`, used to evaluate `failureThreshold`.
 * - `HalfOpen`: a count of probe successes since entering `HalfOpen`, plus the
 *   number of probe slots currently occupied.
 *
 * A `Closed` failure never leaks into the `HalfOpen` probe count and vice
 * versa.
 *
 * @api
 */
interface Storage
{
    /**
     * Atomically decide whether a call may proceed, applying every automatic
     * transition first:
     *
     * - `Open` and `now >= openedAt + cooldown` → transition to `HalfOpen`
     *   (fresh probe counters), then fall through to the `HalfOpen` case below.
     * - `HalfOpen` → occupy one of `probeLimit` probe slots if one is free.
     * - `Closed` → always `Allowed`.
     *
     * Every `Rejected` decision atomically increments the `rejected` counter.
     * A `Probe` decision occupies a slot that MUST be released by exactly one
     * matching `recordOutcome()` call — including when the callback throws.
     *
     * @param non-empty-string $key       breaker name, used as the storage key
     * @param non-empty-string $attemptId unique call identifier used to fence
     *                                    reclaimed distributed probes. MUST be
     *                                    fresh for every call and MUST be
     *                                    passed back to the matching
     *                                    `recordOutcome()`
     */
    public function admit(
        string $key,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        string $attemptId,
    ): AdmissionResult;

    /**
     * Atomically record the outcome of a call previously admitted with
     * `Allowed` or `Probe`, evaluate thresholds, and apply any resulting
     * transition. Returns the state AFTER the update:
     *
     * - `Closed` + `Success`/`Failure` → appended to the ring buffer; if the
     *   resulting failure count reaches `failureThreshold`, transitions to
     *   `Open` (`openedAt = $now`). `Ignored` is a no-op — it is deliberately
     *   excluded from the window.
     * - `HalfOpen` + `Success` → increments the probe-success count; at
     *   `successThreshold`, transitions to `Closed` (ring buffer reset,
     *   `rejected` reset to 0).
     * - `HalfOpen` + `Failure` → transitions to `Open` immediately
     *   (`openedAt = $now`, restarting the cooldown; `rejected` keeps
     *   accumulating — this is the same outage episode, not a new one).
     * - `HalfOpen` + `Ignored` → releases the probe slot, no threshold effect.
     * - `Open` → no-op (a probe that outlived a sibling probe's failure,
     *   which already transitioned the breaker away from `HalfOpen`); its
     *   slot bookkeeping is reclaimed the next time `admit()` re-enters
     *   `HalfOpen`.
     * - A `Probe` outcome observed after a sibling already transitioned to
     *   `Closed` does not enter the new `Closed` window. A late probe failure
     *   reopens immediately; a late success or ignored outcome is a no-op.
     * - `HalfOpen` + a non-`Probe` admission → no-op. An `Allowed` outcome was
     *   admitted while the breaker was still `Closed`; the circuit has opened
     *   and started a fresh probe generation since, so it is not a probe of
     *   this generation and must not move it.
     * - Probe slots are leased for `BreakerConfig::probeTimeout()`. An outcome
     *   whose `$admittedAt` predates the current probe generation (or whose
     *   `$attemptId` is no longer registered, on Redis) is stale and has no
     *   effect.
     *
     * MUST be called exactly once for every `Allowed`/`Probe` returned by
     * `admit()`, including when the callback threw. `$admission`,
     * `$admittedAt`, and `$attemptId` are the fencing triple and are all
     * required: they MUST be the admission returned by `admit()`, the instant
     * passed to it, and the identifier given to it. Passing a fresh or
     * fabricated triple defeats probe fencing.
     *
     * @param non-empty-string $key
     * @param non-empty-string $attemptId the identifier passed to `admit()`
     */
    public function recordOutcome(
        string $key,
        Outcome $outcome,
        BreakerConfig $config,
        \DateTimeImmutable $now,
        Admission $admission,
        \DateTimeImmutable $admittedAt,
        string $attemptId,
    ): OutcomeResult;

    /**
     * Read-only snapshot for metrics. No side effects: does not apply the
     * `Open` → `HalfOpen` cooldown transition, does not prune the ring buffer,
     * and does not mutate any counter. `Closed`/`Open` `successes`/`failures`
     * therefore reflect the ring buffer as of the last `recordOutcome()` call,
     * not a live re-evaluation against the current time — a long-idle breaker
     * can show entries that would have aged out of `within` had a new call
     * arrived.
     *
     * @param non-empty-string $key
     */
    public function snapshot(string $key): StateRecord;

    /**
     * Force an immediate transition, resetting every counter (ring buffer,
     * probe state, `rejected`) as if `$key` had just naturally entered
     * `$state`.
     *
     * @param non-empty-string $key
     */
    public function forceState(
        string $key,
        CircuitState $state,
        \DateTimeImmutable $now,
    ): ?CircuitTransition;
}
