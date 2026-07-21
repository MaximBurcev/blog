# 🏛 Архитектурный аудит — `laravel.local/app`

**Уровень:** standard · **Дата:** 2026-07-05 · **Аудиторы:** architecture-auditor + pattern-auditor

## 1. Executive Summary

Это классическое Laravel MVC + сервисный слой — **не** DDD/Clean/Hexagonal, и для проекта такого размера это оправданный выбор. Поэтому отсутствие DDD/CQRS не считается нарушением. Реальные проблемы сосредоточены в конвейере парсинга (`ReleaseService → ParseLinksJob → StorePostJob → PostService`), который почти целиком состоит из внешнего I/O (fetch страниц, Google Translate, загрузка картинок) и не защищён ничем.

**Оценка здоровья архитектуры: 🟠 5.5/10** — структура вменяемая, но конвейер хрупкий, недурабельный и практически нетестируемый.

- 🔴 Critical: **4**
- 🟠 High: **9**
- 🟡 Medium: **6**
- 🟢 Low: **6**

## 2. Матрица обнаружения паттернов

| Область | Наличие | Compliance | Статус |
|---|---|---|---|
| Layered (MVC + Service) | ✅ | ~70% | Основа проекта |
| DDD / CQRS / Clean / Hexagonal | ❌ | n/a | Не заявлено — не нарушение |
| Event-Driven (Events + Reverb) | ⚠️ Частично | 40% | Только broadcast-push, без листенеров |
| Outbox | ❌ | 0% | Риск потери сообщений |
| Saga (конвейер парсинга) | ⚠️ Неявная | 15% | Без координации/идемпотентности/компенсаций |
| Stability (CB/Retry/Timeout/RateLimit/Bulkhead) | ❌ | ~15% | **Главная проблема** |
| Adapter вокруг внешних SDK | ❌ | 25% | Hard-`new`, нет портов |
| Strategy (пер-сайтовые селекторы) | ⚠️ Строковый суррогат | 40% | if/elseif + `str_contains` |
| Factory / DI | ❌ | 20% | Повсеместный `new` |
| Policy (авторизация) | ❌ | 30% | Проверки `role == 0` вручную |

## 3. 🔴 Critical

**C1 — `dd()` в продакшн-ветке** · `TranslateService.php:86`
Catch-блок вызывает `dd($exception->getMessage())` — убивает воркер/запрос при любой ошибке перевода и глушит все исключения.

**C2 — Очередь по умолчанию `sync`** · `config/queue.php:16`
`QUEUE_CONNECTION=sync` → `StorePostJob::dispatch()` (`ReleaseService.php:375`) выполняется **синхронно внутри HTTP-запроса** создания релиза: до `max_links` статей с таймаутом до 300 c каждая → гарантированный timeout запроса, нулевая durability.

**C3 — Джобы глотают исключения, нет retry/failed()** · `StorePostJob.php:191-201`, `ParseLinksJob.php:47-50`
Нет `$tries`/`$backoff`/`failed()`; `catch (\Throwable) → return`. Механизм ретраев Laravel и `failed_jobs` никогда не срабатывает — это конкретный механизм потери статей.

**C4 — SRP/DIP: `StorePostJob` — God object** · `StorePostJob.php:47-204`
Один `handle()` (~160 строк) делает fetch (curl/HTTP), DOM-парсинг, og:image, извлечение заголовка, детект категории, перевод, загрузку картинок, персист. ≥8 причин для изменения + мёртвые методы (`showDOMNode:206`, `replaceTextInNodes:232`). Нетестируем без сети.

## 4. 🟠 High (warnings)

| # | Проблема | Файл |
|---|---|---|
| H1 | Нет resilience ни на одном внешнем вызове (translate/fetch/images): ни CB, ни backoff, ни единого timeout в `ParseLinksJob` | `ParseLinksJob.php:43`, `TranslatesNodes.php:32-55`, `ContentImageService.php:25,64` |
| H2 | Конвейер-«сага» без идемпотентности: повторный запуск релиза плодит дубликаты постов, осиротевшие картинки | `PostService.php:55`, `ContentImageService.php:34` |
| H3 | Нет Outbox: dispatch джоб/нотификаций вне транзакции; смерть процесса между `commit` и `dispatch` → потеря | `ReleaseService.php:256`, `PostService.php:77-82` |
| H4 | Синхронный неограниченный fan-out нотификаций внутри сервиса (все админы в цикле, mail по SMTP inline) | `PostService.php:74-86` |
| H5 | SSRF: allow-list доменов (`isDomainAllowed`) не применяется на этапе fetch статьи/картинок | `StorePostJob.php:61`, `ContentImageService.php:64` |
| H6 | `abort(500)` как control flow в сервисе, вызываемом из джобы — связывает бизнес-логику с HTTP-слоем | `PostService.php:71,144` |
| H7 | Нет Adapter вокруг GoogleTranslate/Guzzle/curl → корень DIP-нарушений, блокирует любые stability-декораторы | `TranslateService.php:25`, `ParseLinksJob.php:43`, `StorePostJob.php:61` |
| H8 | Отсутствует Strategy для пер-сайтового извлечения: `getSelectorForUrl` + if/elseif по форме селектора (`#`/`.`/tag) | `ReleaseService.php:353-364`, `StorePostJob.php:140-151` |
| H9 | Повсеместный hard-`new` вместо DI/Factory — ничего не мокается | `StorePostJob.php:42-49`, `PostService.php:43-127`, `TranslateService.php:24-30` |

## 5. 🟡 Medium / 🟢 Low

- 🟡 `Post::$guarded = false` (`Post.php:20`) — mass-assignment на скрейпленных данных; заменить на `$fillable`.
- 🟡 Дублирование Template Method: `modifyContent()` скопирован в `StorePostJob.php:277` и `TranslateService.php:94`; `extractFirstImagePath()` в `PostService.php:151` и `StorePostJob.php:252`.
- 🟡 `TranslateService` создаёт транслятор дважды (`:25` мёртвое поле, используется локальный `:30`).
- 🟡 Нет Rate Limiter / Bulkhead на исходящие: до 20 `StorePostJob` разом без спейсинга, риск 429/бана; scraping не изолирован в отдельную очередь.
- 🟡 Нет Policy-классов; авторизация — разбросанные `role == 0`.
- 🟢 Null Object для детекторов (`CategoryDetectorService::detect(): ?int`) убрал бы null-ветвления.
- 🟢 Типизированный `PostDraft` DTO/Builder вместо «stringly-typed» массива `$data`.
- 🟢 Correlation ID через весь конвейер для трассировки.
- 🟢 Read Model для публичного листинга (`Post::$with`, `likesCount()` — запрос на вызов).

## 6. Cross-Pattern конфликты

| Паттерны | Проблема | Резолюция |
|---|---|---|
| Saga + Outbox | Состояние коммитится, side-effects диспатчатся вне транзакции (усугубляется `sync`) | `DB::afterCommit()` или outbox-таблица |
| Saga + Idempotency | Повторы → дубликаты постов + осиротевшие картинки | Ключ идемпотентности (hash URL) + unique index + cleanup в `failed()` |
| EDA + Resilience | Broadcast/notify без retry/outbox — тихо теряются | Один queued-листенер с retry |
| Layered + Clean | Сервис бросает HTTP `abort(500)` и `dd()` | Application-исключения |

## 7. Приоритетные действия → навыки генерации

### 🔴 Critical (делать первым)
1. Убрать `dd()` из `TranslateService.php:86` → логирование + rethrow.
2. Запретить `sync` в не-local окружениях (`QUEUE_CONNECTION=database/redis`).
3. Добавить `$tries` / `$backoff` / `failed()` в `StorePostJob`/`ParseLinksJob`; перестать глотать `\Throwable`.

### 🟠 High — рекомендованные паттерны

| Проблема | Паттерн | Навык |
|---|---|---|
| H1/H7 внешние вызовы без защиты | Circuit Breaker + Retry + Timeout + Adapter | `/acc:create-circuit-breaker`, `/acc:create-retry-pattern`, `/acc:create-timeout`, `/acc:create-adapter` |
| H3 потеря сообщений | Outbox | `/acc:create-outbox-pattern` |
| H2 дубликаты в конвейере | Idempotency + Saga | `/acc:create-idempotency-handler`, `/acc:create-saga-pattern` |
| H8 пер-сайтовое извлечение | Strategy | `/acc:create-strategy` |
| H5 SSRF | применить allow-list на fetch | (правка `isDomainAllowed`) |

### 🟡 Medium
- `Notification::send($admins)` + queued-листенер вместо inline-цикла (H4).
- Policy для Post/Comment/Release: `/acc:create-policy`.
- `PostDraft` DTO/Builder: `/acc:create-dto`, `/acc:create-builder`.

---

**Итог:** архитектурный стиль менять не нужно — болит не «отсутствие DDD», а **надёжность конвейера парсинга**. Самый высокий ROI: `dd()` + durability очереди + retry/failed (Critical), затем Adapter + CircuitBreaker вокруг translate/fetch/images.
