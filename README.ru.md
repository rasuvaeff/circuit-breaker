# rasuvaeff/circuit-breaker

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/circuit-breaker/v)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/circuit-breaker/downloads)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![Build](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/circuit-breaker/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/circuit-breaker/php)](https://packagist.org/packages/rasuvaeff/circuit-breaker)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Resilience-примитив circuit breaker: защищает downstream-зависимость от
каскадного отказа. Состояние `Closed → Open → HalfOpen` переключается по
sliding-window счётчикам success/failure. В `Open` вызовы отклоняются мгновенно
— без сетевого round-trip — до истечения cooldown; затем ограниченное число
`HalfOpen`-зондов решает, восстанавливать трафик или снова открыть цепь.

Подключаемое распределённое состояние через `Storage`: in-memory (тесты/CLI),
APCu (один хост), Redis (кластер из нескольких хостов) — все три реализуют
один и тот же атомарный контракт `admit()`/`recordOutcome()`, поэтому смена
backend'а никогда не меняет поведение, только то, насколько далеко
распространяется общее состояние.

> Используете AI coding assistant? [llms.txt](llms.txt) содержит компактный API-справочник, которым можно поделиться с моделью.

## Требования

- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) для типизированных значений cooldown/window
- `psr/clock` — инъекция любого `ClockInterface`; в комплекте `Clock\SystemClock`
  (production) и `Clock\FakeClock` (тесты)
- Для состояния на несколько хостов (`RedisStorage`): доступный сервер Redis плюс
  **один** Redis-клиент — [`predis/predis`](https://github.com/predis/predis) ^2.2
  (чистый PHP, `PredisScriptRunner`) либо `ext-redis` (`PhpRedisScriptRunner`).
  Обе зависимости опциональны; ставьте ту, которую используете.
- `ext-apcu` для состояния на одном хосте (`ApcuStorage`) — опционально, не жёсткая зависимость

## Установка

```bash
composer require rasuvaeff/circuit-breaker

# для RedisStorage с чистым PHP-клиентом:
composer require predis/predis
```

## Использование

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
        cooldown: Duration::seconds(30),   // сколько длится Open до допуска зонда
        successThreshold: 3,               // подряд успешных зондов для возврата в Closed
        // Классифицируйте только исключения, означающие отказ downstream.
        isFailure: static fn(\Throwable $e): bool => $e instanceof ClientExceptionInterface,
    ),
    storage: new InMemoryStorage(), // или ApcuStorage / RedisStorage — тот же контракт
    clock: new SystemClock(),
);

$charge = $cb->call(
    callback: static fn(): mixed => $stripe->charges->create([/* ... */]),
    fallback: static fn(\Throwable $e): mixed => ChargeResult::queuedForRetry(),
);

if ($cb->state()->value === 'open') {
    // показать degrade UI, не пытаясь выполнить вызов
}
```

С Redis (несколько хостов):

```php
use Predis\Client;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage;

$storage = new RedisStorage(new PredisScriptRunner(new Client(['host' => '127.0.0.1'])));
```

С `ext-redis` вместо predis:

```php
use Rasuvaeff\CircuitBreaker\Redis\PhpRedisScriptRunner;

$redis = new \Redis();
$redis->connect('127.0.0.1');
$storage = new RedisStorage(new PhpRedisScriptRunner($redis));
```

С APCu (один хост):

```php
use Rasuvaeff\CircuitBreaker\ApcuStorage;

$storage = new ApcuStorage();
```

### Публичный API

| Тип | Описание |
|---|---|
| `CircuitBreaker` | `call(callable, ?callable $fallback): mixed`, `canCall()`, `state()`, `metrics()`, `forceOpen()`, `forceClosed()` |
| `BreakerConfig` | `name`, `failureThreshold` (`Ratio`), `cooldown`, `successThreshold`, обязательный `isFailure`, `probeLimit`, `probeTimeout` |
| `Ratio` | «N отказов из последних M вызовов, в пределах скользящего окна» — задаёт `failureThreshold` |
| `CircuitState` | Enum: `Closed`, `Open`, `HalfOpen` |
| `Outcome` | Enum: `Success`, `Failure`, `Ignored` — результат классификации `isFailure()` |
| `Admission` | Enum: `Allowed`, `Probe`, `Rejected` — решение `Storage::admit()` |
| `Storage` | Интерфейс: `admit`, `recordOutcome`, `snapshot`, `forceState` — шов распределённого состояния. `admit()`/`recordOutcome()` требуют fencing-тройку (`admission`, `admittedAt`, `attemptId`) |
| `StorageFailure` | Инфраструктурное исключение отказа storage: `operation`, `breakerName` и исходная ошибка |
| `InMemoryStorage` | Однопроцессное хранилище (тесты/CLI); без межпроцессной координации |
| `ApcuStorage` | Межпроцессное хранилище на одном хосте; lock на `apcu_add` (lease, `lockTtlSeconds`) вокруг всего цикла чтение-переход-запись |
| `RedisStorage` | Межпроцессное хранилище на несколько хостов; один Lua-скрипт на каждый метод `Storage` |
| `CircuitScriptRunner` | Типизированный шов над вызовом Redis-скрипта (реализуйте для другого клиента) |
| `Redis\PredisScriptRunner` | `CircuitScriptRunner` на predis; EVALSHA с fallback на EVAL |
| `Redis\PhpRedisScriptRunner` | `CircuitScriptRunner` на `ext-redis`; EVALSHA с fallback на EVAL |
| `StateRecord` | Снимок, возвращаемый `Storage`: `state`, `openedAt`, `successes`, `failures`, `rejected` |
| `Metrics` | Снимок для observability из `CircuitBreaker::metrics()`, зеркалит `StateRecord` |
| `CircuitTransition` | Зафиксированный переход: `breakerName`, `from`, `to`, `occurredAt`, `reason`, `state` |
| `TransitionReason` | Enum: причина перехода — `FailureThresholdReached`, `CooldownElapsed`, `ProbeSucceeded`, `ProbeFailed`, `ForcedOpen`, `ForcedClosed`, `ForcedHalfOpen` |
| `CircuitObserver` | Интерфейс получения зафиксированных `CircuitTransition`; используется в паре с error handler |
| `CircuitOpenException` | Бросается (или передаётся в `fallback`) при отклонении вызова; несёт `breakerName`, `retryAfter` |
| `Clock\SystemClock` | `Psr\Clock\ClockInterface` на системных часах |
| `Clock\FakeClock` | Управляемые часы для тестирования истечения cooldown/window |

### Как `call()` принимает решение

1. `Storage::admit()` — атомарно применяет переход `Open → HalfOpen` по
   cooldown, затем решает `Allowed` (`Closed`) / `Probe` (`HalfOpen`, слот
   занят) / `Rejected` (`Open`, либо `HalfOpen` без свободного слота).
2. `Rejected` → `$callback` **никогда не вызывается**. Если задан `$fallback`,
   он получает свежий `CircuitOpenException`; иначе исключение бросается. В
   `Open` поле `retryAfter` указывает на окончание cooldown; при заполненном
   `HalfOpen` это консервативный deadline на основе `probeTimeout`.
3. `Allowed`/`Probe` → `$callback` выполняется. Его исход классифицируется
   через `isFailure()` в `Success` / `Failure` / `Ignored` и записывается
   ровно одним вызовом `Storage::recordOutcome()` (это же освобождает
   `HalfOpen`-слот зонда — в том числе если `$callback` бросил исключение).
   Timestamp берётся при завершении callback. Исходный `Admission` и opaque
   attempt ID передаются в storage, чтобы освобождённый по timeout зонд не мог
   изменить новое поколение или попасть в уже сброшенное окно `Closed`.
4. Исход `Failure` при заданном `$fallback` возвращает результат fallback'а;
   иначе исходное исключение пробрасывается. Исход `Ignored`
   (`isFailure() === false`) **всегда** пробрасывается как есть — он никогда
   не триггерит `$fallback` и не засчитывается в порог.

### Настройка параметров

- **`failureThreshold`** (`Ratio::of(failures, window, within)`) — кольцевой
  буфер, ограниченный `window` записями и обрезаемый по последней длительности
  `within`. `Ratio::of(failures: 5, window: 10, within: seconds(60))` открывает
  цепь, когда 5 из последних (до 10, в пределах 60с) вызовов в `Closed`
  завершились отказом.
- **`cooldown`** — сколько длится `Open` до допуска первого зонда. Слишком
  короткий — зонды бьют по ещё не восстановившейся зависимости; слишком
  длинный — восстановление задерживается, когда зависимость уже здорова.
- **`successThreshold`** — подряд успешных `HalfOpen`-зондов для закрытия.
  `1` закрывает по первому успешному зонду; большие значения требуют
  устойчивого восстановления перед возобновлением полного трафика.
- **`probeLimit`** — сколько зондов допускается одновременно в `HalfOpen`
  (по умолчанию `1`). `1` — самый безопасный вариант (одиночный
  canary-вызов); поднятие до 3-5 ускоряет восстановление ценой большего
  трафика на потенциально ещё нездоровую зависимость.
- **`probeTimeout`** — максимальная длительность lease зонда `HalfOpen`,
  по умолчанию равна `cooldown`. Заброшенные слоты освобождаются автоматически.
- **`isFailure`** — обязательный классификатор. Фильтруйте только исключения,
  действительно указывающие на нездоровье *downstream* (сетевые ошибки,
  ответы 5xx) — см. [«Особенности»](#особенности).

### Как состояние удерживается между воркерами/хостами

`RedisStorage` выполняет `admit()`/`recordOutcome()`/`snapshot()`/`forceState()`
каждый как ровно один Lua-скрипт. Redis выполняет скрипты до конца без
чередования с командой другого клиента, поэтому последовательность
чтение-оценка-переход (проверить счётчики → решить → записать новое
состояние) атомарна для любого числа процессов, соревнующихся за один и тот
же breaker — разбиение этого на отдельные вызовы `INCR`/`GET`/`SET` заново
открыло бы ту самую гонку check-then-act, для устранения которой пакет и
создан. Состояние живёт в одном Redis-хеше (`state`, `openedAt`, счётчики
зондов), одном sorted set на breaker (скользящее окно `Closed`, member —
исход, score — timestamp) и set opaque ID активных зондов. Все ключи используют
общий Redis Cluster hash tag, поэтому каждый multi-key скрипт работает в
пределах одного cluster slot.

`ApcuStorage` приближается к этому через lock на `apcu_add` вокруг
чтения-перехода-записи всей записи целиком (у APCu нет server-side
скриптинга). Lock содержит integer owner token и освобождается через CAS,
поэтому старый владелец не может снять новый lock после истечения своей lease.
Этот lock — **lease** (`lockTtlSeconds`, по умолчанию 1s), а не мьютекс уровня
Redis: критическая секция, пережившая свою lease, иначе записала бы поверх
воркера, уже перехватившего работу, поэтому перед commit проверяется владение
и при потере lease поднимается `StorageFailure`. Это сужает окно, но не
закрывает его — APCu не умеет выполнить проверку владения и запись значения
одним атомарным шагом, так что при долгих задержках воркеров увеличивайте
`lockTtlSeconds`. Отказ `apcu_store()` (исчерпана разделяемая память) тоже
поднимает `StorageFailure`: незаписанный переход никогда не отчитывается как
зафиксированный. APCu координирует только воркеров на **одном хосте** — для
пула, распределённого по хостам, используйте `RedisStorage`.

## Безопасность

- `name` валидируется по `/^[A-Za-z0-9_.:-]+$/` и становится частью
  Redis/APCu-ключа — недоверенные имена отклоняются, а не слепо
  интерполируются.
- Значения попадают в Lua-скрипты как связанные `ARGV`/`KEYS`, никогда через
  конкатенацию строк.
- Каждый `CircuitBreaker::call()` несёт opaque attempt ID. Redis хранит ID
  активных зондов и атомарно игнорирует исходы освобождённых поколений.
- Пакет сам не открывает сетевых соединений; Redis-клиент предоставляете вы.

## Особенности

- **`isFailure` обязателен.** Передавайте классификатор, инспектирующий
  тип/статус исключения, чтобы ошибки вызывающего кода не открывали цепь.
- **Ошибки storage не являются ошибками downstream.** Исключение из
  `recordOutcome()` оборачивается в `StorageFailure`, не проходит через
  `isFailure` и не вызывает `fallback`; wrapper содержит операцию и
  исходное исключение в `getPrevious()`. См. паттерн логирования и деградации
  в `examples/07-storage-outage.php`.
- **Часы и режим времени.** `RedisStorage` по умолчанию использует время Redis
  для cooldown и lease зондов. Передавайте `useServerTime: false` только для
  детерминированных тестов: этот режим сравнивает часы вызывающей стороны и
  требует синхронизации. APCu всегда использует часы вызывающей стороны,
  поэтому для него также нужен NTP. Fencing зондов не зависит от синхронизации
  часов: Redis проверяет opaque attempt ID по активному поколению в обоих
  режимах времени.
- **`snapshot()` никогда не мутирует.** Он не применяет отложенный переход
  `Open` → `HalfOpen` по cooldown и не обрезает скользящее окно — это делают
  только `admit()`/`recordOutcome()` внутри `call()`. Поэтому
  `state()`/`metrics()` отражают состояние на момент последнего реального
  вызова, а не живую переоценку по текущему времени.
- **`InMemoryStorage` только для одного процесса** — он **не** координирует
  между воркерами FPM-пула. Используйте его для тестов и CLI.
- **`ApcuStorage` координирует только воркеров на одной машине.** Для пула,
  распределённого по хостам, нужен `RedisStorage`. Если конкуренция исчерпала
  бюджет spin-блокировки, операция бросает исключение, а `CircuitBreaker`
  возвращает `StorageFailure`; переходы состояния никогда не теряются молча.
- **Пакет не делает retry и не ограничивает параллелизм.** Компонуйте с
  [`rasuvaeff/retry`](https://github.com/rasuvaeff/retry) (повторяет
  транзиентные ошибки *внутри* одного вызова) и
  [`rasuvaeff/bulkhead`](https://github.com/rasuvaeff/bulkhead) (ограничивает
  число одновременных вызовов) — см. `examples/03-with-retry.php` и
  `examples/04-with-bulkhead.php`.

## Классификация результатов и переходы

`BreakerConfig::$classifyResult` необязателен: по умолчанию обычный результат
callback считается `Outcome::Success`. Настройте его, если API сообщает об
отказе обычным значением; исходное значение всё равно возвращается, fallback не
вызывается.

Передайте `CircuitObserver` и парный обработчик ошибок в `CircuitBreaker`, чтобы
получать подтверждённые `CircuitTransition` без polling `metrics()`.
`Storage::admit()` возвращает `AdmissionResult`, а `recordOutcome()` —
`OutcomeResult`; оба объекта могут содержать данные перехода.

Если реализуете `Storage` сами: `$admission`, `$admittedAt` и `$attemptId` —
обязательные параметры `recordOutcome()` и должны быть ровно тем, что вернул и
получил `admit()`. Исход, чья admission не `Admission::Probe`, никогда не
должен двигать breaker в `HalfOpen` — он был допущен, пока цепь была `Closed`,
и не принадлежит ни одному поколению зондов.

## Примеры

См. [examples/](examples/) — исполняемые скрипты.
Примеры должны выполняться без фатальных ошибок и соответствовать
документированному публичному API.

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `01-in-memory.php` | Минимальный breaker: срабатывание по `failureThreshold`, `fallback` | нет |
| `02-redis-cluster.php` | Состояние на несколько хостов через `RedisStorage` + predis | да |
| `03-with-retry.php` | Композиция с `rasuvaeff/retry` | нет |
| `04-with-bulkhead.php` | Композиция с `rasuvaeff/bulkhead` | нет |
| `05-prometheus-metrics.php` | Экспорт `Metrics` в формате Prometheus exposition | нет |
| `06-apcu.php` | Межпроцессное состояние на одном хосте через `ApcuStorage` | нет (нужен `ext-apcu`) |
| `07-storage-outage.php` | Отдельная обработка `StorageFailure` и ошибок downstream | нет |

## Разработка

PHP/Composer на хосте нет — всё через Docker (`composer:2` image):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Интеграционным тестам нужен сервер Redis (self-skip без `REDIS_HOST`),
`ext-apcu` (self-skip через `ApcuStorage::isAvailable()`) и `ext-redis`
(self-skip через `extension_loaded('redis')`); в базовом образе `composer:2`
их нет, поэтому запускайте suite в образе с `apcu`, `pcntl` и `redis`
(плюс `apc.enable_cli=1`):

```bash
docker run -d --name cb-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  <php-образ-с-apcu-pcntl-redis> vendor/bin/testo --suite=Integration
docker rm -f cb-redis
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
