# Examples

Runnable scripts demonstrating `rasuvaeff/circuit-breaker`.

```bash
composer install
php examples/01-in-memory.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `01-in-memory.php` | Minimal breaker: tripping on `failureThreshold`, `fallback` | no |
| `02-redis-cluster.php` | Cross-host state via `RedisStorage` + predis | yes |
| `03-with-retry.php` | Composition with `rasuvaeff/retry` (retries inside one call, breaker blocks after outage) | no |
| `04-with-bulkhead.php` | Composition with `rasuvaeff/bulkhead` (nested `call()`) | no |
| `05-prometheus-metrics.php` | Exporting `Metrics` in Prometheus exposition format | no |
| `06-apcu.php` | Single-host cross-process state via `ApcuStorage` | no (needs `ext-apcu`) |
| `07-storage-outage.php` | Distinguishing storage outage from downstream failure | no |

`02-redis-cluster.php` self-skips with a message unless `REDIS_HOST` is set:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php examples/02-redis-cluster.php
```

`03-with-retry.php` / `04-with-bulkhead.php` self-skip with a message unless
`rasuvaeff/retry` / `rasuvaeff/bulkhead` are installed alongside this package
(they are optional composition partners, not dependencies — see
`composer require --dev rasuvaeff/retry rasuvaeff/bulkhead`).

`06-apcu.php` self-skips with a message unless `ext-apcu` is loaded and
enabled for the CLI SAPI (`apc.enable_cli=1`):

```bash
php -d apc.enable_cli=1 examples/06-apcu.php
```
