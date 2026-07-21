<?php

declare(strict_types=1);

namespace Rasuvaeff\CircuitBreaker\Redis;

/**
 * The four scripts backing {@see \Rasuvaeff\CircuitBreaker\RedisStorage}, one
 * per {@see \Rasuvaeff\CircuitBreaker\Storage} method. Each runs as a single
 * atomic Redis script (Redis is single-threaded, so a script always
 * completes without interleaving another client's command) — this is what
 * makes the read-evaluate-transition sequence race-free across processes.
 *
 * Data model per breaker key `K`:
 * - `HASH K`: `state` (`closed`|`open`|`half-open`), `openedAt` (epoch ms),
 *   `probeSuccesses`, `probeSlots`, `rejected`, `seq` (ring member counter).
     * - `ZSET K:ring`: one member per `Closed`-state outcome, scored by its
 *   timestamp (epoch ms); member = `"<seq>:<f|s>"` so the outcome
 *   (`f`ailure/`s`uccess) is recoverable without a second lookup. Bounded by
     *   both `window` (count, via `ZREMRANGEBYRANK`) and `within` (time, via
     *   `ZREMRANGEBYSCORE`) on every `Closed` outcome.
     * - `SET K:probes`: opaque IDs of probes admitted in the current generation.
 *
 * @internal
 */
final class LuaScripts
{
    private function __construct() {}

    /**
     * KEYS[1] = hash, KEYS[2] = probes, ARGV[1] = callerNowMs,
     * ARGV[2] = cooldownMs, ARGV[3] = probeLimit,
     * ARGV[4] = probeTimeoutMs, ARGV[5] = useServerTime,
     * ARGV[6] = attemptId.
     *
     * Returns `{admission, previousState, transitionReason, openedAt,
     * rejected, occurredAt}`.
     */
    public const string ADMIT = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state')
        if state == false then state = 'closed' end
        local previousState = state
        local transitionReason = ''
        local openedAt = tonumber(redis.call('HGET', KEYS[1], 'openedAt') or '0')
        local now = tonumber(ARGV[1])
        if ARGV[5] == '1' then
            local serverTime = redis.call('TIME')
            now = tonumber(serverTime[1]) * 1000 + math.floor(tonumber(serverTime[2]) / 1000)
        end
        local cooldown = tonumber(ARGV[2])
        local probeLimit = tonumber(ARGV[3])
        local probeTimeout = tonumber(ARGV[4])
        local attemptId = ARGV[6]

        if state == 'open' then
            if now >= openedAt + cooldown then
                state = 'half-open'
                transitionReason = 'cooldown-elapsed'
                redis.call('HSET', KEYS[1], 'state', state, 'probeSlots', 0, 'probeSuccesses', 0, 'probeGenerationAt', now)
                redis.call('DEL', KEYS[2])
            else
                local rejected = redis.call('HINCRBY', KEYS[1], 'rejected', 1)
                return {'rejected', previousState, '', tostring(openedAt), tostring(rejected), tostring(now)}
            end
        end

        if state == 'half-open' then
            local probeSlots = tonumber(redis.call('HGET', KEYS[1], 'probeSlots') or '0')
            local probeGenerationAt = tonumber(redis.call('HGET', KEYS[1], 'probeGenerationAt') or tostring(now))
            -- probeGenerationAt only drives lease expiry here; outcome fencing
            -- is by probes-SET membership, see RECORD_OUTCOME.
            if probeSlots > 0 and now >= probeGenerationAt + probeTimeout then
                probeSlots = 0
                probeGenerationAt = now
                redis.call('HSET', KEYS[1], 'probeSlots', 0, 'probeGenerationAt', now)
                redis.call('DEL', KEYS[2])
            elseif probeSlots == 0 then
                probeGenerationAt = now
                redis.call('HSET', KEYS[1], 'probeGenerationAt', now)
            end
            local probeLimitReached = probeSlots >= probeLimit
            if probeLimitReached then
                local rejected = redis.call('HINCRBY', KEYS[1], 'rejected', 1)
                return {'rejected', previousState, '', tostring(openedAt), tostring(rejected), tostring(now)}
            end

            redis.call('HINCRBY', KEYS[1], 'probeSlots', 1)
            redis.call('SADD', KEYS[2], attemptId)

            local rejected = tonumber(redis.call('HGET', KEYS[1], 'rejected') or '0')
            return {'probe', previousState, transitionReason, tostring(openedAt), tostring(rejected), tostring(now)}
        end

        local rejected = tonumber(redis.call('HGET', KEYS[1], 'rejected') or '0')
        return {'allowed', previousState, '', tostring(openedAt), tostring(rejected), tostring(now)}
        LUA;

    /**
     * KEYS[1] = hash, KEYS[2] = ring, KEYS[3] = probes,
     * ARGV[1] = nowMs,
     * ARGV[2] = outcome ("success"|"failure"|"ignored"),
     * ARGV[3] = failureThreshold, ARGV[4] = window, ARGV[5] = withinMs,
     * ARGV[6] = successThreshold, ARGV[7] = admission ("allowed"|"probe"),
     * ARGV[8] = useServerTime, ARGV[9] = attemptId.
     *
     * Probe fencing is by `attemptId` membership in the probes SET, never by
     * timestamp: the set is cleared whenever a new probe generation starts, so
     * a reclaimed probe is detected without relying on clock synchronization
     * between the caller and Redis.
     *
     * Returns `{state, openedAtMs, successes, failures, rejected,
     * previousState, transitionReason, occurredAt}` (all as strings).
     */
    public const string RECORD_OUTCOME = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state')
        if state == false then state = 'closed' end
        local previousState = state
        local transitionReason = ''
        local now = tonumber(ARGV[1])
        if ARGV[8] == '1' then
            local serverTime = redis.call('TIME')
            now = tonumber(serverTime[1]) * 1000 + math.floor(tonumber(serverTime[2]) / 1000)
        end
        local outcome = ARGV[2]
        local admission = ARGV[7]
        local attemptId = ARGV[9]
        local staleProbe = false
        if admission == 'probe' then
            staleProbe = redis.call('SISMEMBER', KEYS[3], attemptId) == 0
        end

        if admission == 'probe' and not staleProbe then
            redis.call('SREM', KEYS[3], attemptId)
        end

        if state == 'closed' then
            if staleProbe then
                -- no-op
            elseif admission == 'probe' then
                if outcome == 'failure' then
                    state = 'open'
                    transitionReason = 'probe-failed'
                    redis.call('HSET', KEYS[1], 'state', 'open', 'openedAt', now, 'rejected', 0)
                end
            elseif outcome ~= 'ignored' then
                local failureThreshold = tonumber(ARGV[3])
                local window = tonumber(ARGV[4])
                local withinMs = tonumber(ARGV[5])

                local seq = redis.call('HINCRBY', KEYS[1], 'seq', 1)
                local suffix = outcome == 'failure' and 'f' or 's'
                redis.call('ZADD', KEYS[2], now, seq .. ':' .. suffix)

                redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', '(' .. tostring(now - withinMs))

                local card = redis.call('ZCARD', KEYS[2])
                if card > window then
                    redis.call('ZREMRANGEBYRANK', KEYS[2], 0, card - window - 1)
                end

                local failures = 0
                for _, m in ipairs(redis.call('ZRANGE', KEYS[2], 0, -1)) do
                    if string.sub(m, -1) == 'f' then
                        failures = failures + 1
                    end
                end

                if failures >= failureThreshold then
                    state = 'open'
                    transitionReason = 'failure-threshold-reached'
                    redis.call('HSET', KEYS[1], 'state', 'open', 'openedAt', now, 'rejected', 0)
                end
            end
        elseif state == 'half-open' and admission == 'probe' and not staleProbe then
            local probeSlots = tonumber(redis.call('HGET', KEYS[1], 'probeSlots') or '0')
            if probeSlots > 0 then
                redis.call('HSET', KEYS[1], 'probeSlots', probeSlots - 1)
            end

            if outcome == 'success' then
                local successThreshold = tonumber(ARGV[6])
                local probeSuccesses = redis.call('HINCRBY', KEYS[1], 'probeSuccesses', 1)
                if probeSuccesses >= successThreshold then
                    state = 'closed'
                    transitionReason = 'probe-succeeded'
                    redis.call('HSET', KEYS[1], 'state', 'closed', 'rejected', 0, 'probeSuccesses', 0, 'probeSlots', 0)
                    redis.call('DEL', KEYS[2])
                end
            elseif outcome == 'failure' then
                state = 'open'
                transitionReason = 'probe-failed'
                redis.call('HSET', KEYS[1], 'state', 'open', 'openedAt', now, 'probeSuccesses', 0, 'probeSlots', 0)
            end
        end

        local openedAt = tonumber(redis.call('HGET', KEYS[1], 'openedAt') or '0')
        local rejected = tonumber(redis.call('HGET', KEYS[1], 'rejected') or '0')
        local successes = 0
        local failures = 0

        if state == 'half-open' then
            successes = tonumber(redis.call('HGET', KEYS[1], 'probeSuccesses') or '0')
        else
            for _, m in ipairs(redis.call('ZRANGE', KEYS[2], 0, -1)) do
                if string.sub(m, -1) == 'f' then
                    failures = failures + 1
                else
                    successes = successes + 1
                end
            end
        end

        return {state, tostring(openedAt), tostring(successes), tostring(failures), tostring(rejected), previousState, transitionReason, tostring(now)}
        LUA;

    /**
     * KEYS[1] = hash, KEYS[2] = ring. No ARGV.
     *
     * Read-only. Returns `{state, openedAtMs, successes, failures, rejected}`.
     */
    public const string SNAPSHOT = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state')
        if state == false then state = 'closed' end

        local openedAt = tonumber(redis.call('HGET', KEYS[1], 'openedAt') or '0')
        local rejected = tonumber(redis.call('HGET', KEYS[1], 'rejected') or '0')
        local successes = 0
        local failures = 0

        if state == 'half-open' then
            successes = tonumber(redis.call('HGET', KEYS[1], 'probeSuccesses') or '0')
        else
            for _, m in ipairs(redis.call('ZRANGE', KEYS[2], 0, -1)) do
                if string.sub(m, -1) == 'f' then
                    failures = failures + 1
                else
                    successes = successes + 1
                end
            end
        end

        return {state, tostring(openedAt), tostring(successes), tostring(failures), tostring(rejected)}
        LUA;

    /**
     * KEYS[1] = hash, KEYS[2] = ring, KEYS[3] = probes, ARGV[1] = state,
     * ARGV[2] = callerNowMs, ARGV[3] = useServerTime.
     */
    public const string FORCE_STATE = <<<'LUA'
        local previousState = redis.call('HGET', KEYS[1], 'state')
        if previousState == false then previousState = 'closed' end
        local now = tonumber(ARGV[2])
        if ARGV[3] == '1' then
            local serverTime = redis.call('TIME')
            now = tonumber(serverTime[1]) * 1000 + math.floor(tonumber(serverTime[2]) / 1000)
        end

        redis.call('DEL', KEYS[1])
        redis.call('DEL', KEYS[2])
        redis.call('DEL', KEYS[3])
        redis.call('HSET', KEYS[1], 'state', ARGV[1], 'openedAt', now, 'probeSuccesses', 0, 'probeSlots', 0, 'probeGenerationAt', now, 'rejected', 0)

        return {previousState, tostring(now)}
        LUA;
}
