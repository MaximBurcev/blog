# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Docker Environment

All PHP/Artisan commands must be run inside the `laravel.test` container (Laravel Sail). Check the running container name with `docker ps`, then:

```bash
docker exec -it <container_name> php artisan <command>
# e.g.: docker exec -it laravellocal-laravel.test-1 php artisan tinker
```

Alternative via Sail:
```bash
./vendor/bin/sail artisan <command>
```

## Common Commands

```bash
# Tests
docker exec -it <container> php artisan test
docker exec -it <container> php artisan test --filter=TestName  # single test

# Queue worker
docker exec -it <container> php artisan queue:work

# Code style (Laravel Pint)
docker exec -it <container> ./vendor/bin/pint

# Static analysis
docker exec -it <container> ./vendor/bin/phpstan analyse
docker exec -it <container> ./vendor/bin/psalm

# Frontend
npm run dev   # Vite dev server
npm run build # Production build

# Deploy (from host, uses Envoy)
./vendor/bin/envoy run deploy
```

## Architecture Overview

### Admin Interface

**Filament Panel** at `/filament` — the only admin panel. Built with Filament 3, auto-discovers resources in `app/Filament/Resources/`. Configured in `app/Providers/Filament/FilamentPanelProvider.php`; access is gated by `User::canAccessPanel()` (role `UserRole::Admin`).

The former custom AdminLTE admin at `/admin` was disabled on 2026-07-27 and removed on 2026-07-30 together with its controllers, form requests, Blade views, Livewire components and the `jeroennoten/laravel-adminlte` package — everything had moved to Filament. The `admin` middleware alias (`AdminMiddleware`) is kept for future protected routes, though nothing uses it right now.

### Database

Single MySQL database, default `mysql` connection (`config/database.php`), configured via `DB_*` env vars. The former `secondary` connection (remote posts DB) was decommissioned in July 2026 — all models, including `Post`, use the default connection.

### Controller Pattern

All controllers are single-action classes — each HTTP action (index, show, store, etc.) has its own dedicated controller class. Example: `Post/ShowController.php`, `Category/IndexController.php`.

### Services (`app/Service/`)

- **PostService** — creates/updates posts, handles image upload to `storage/public/images`, optionally runs translation via `TranslateService`
- **ReleaseService** — stores Release URLs, parses external pages with CSS selectors (via Symfony DomCrawler), dispatches `StorePostJob` for each found link. Configurable via `config/releases.php`
- **ContentImageService** — downloads external images referenced in post content, saves them locally to `storage/public/images/content/`
- **TranslateService** — wraps Google Translate for post content translation

### Async Jobs (`app/Jobs/`)

- **StorePostJob** — fetches an external URL, extracts content by CSS selector, translates text nodes to Russian via Google Translate (skipping `<code>` tags), downloads images via `ContentImageService`, then calls `PostService::store()`
- **ParseReleaseJob** — parses links from a release URL
- **GenerateImageVariantsJob** — generates WebP variants for post images

`StoreUserJob` was removed on 2026-08-09: it was never dispatched anywhere, mass-assigned `$data` wholesale (including `role`, i.e. a ready-made privilege escalation for whoever wired it up), and put the plaintext password into the queue payload.

### News section

`/news` — лента новостей из секции «News and Announcements» дайджеста PHP Weekly
(тех же `Release`, что питают статьи). Новость — это `Post` с флагом `is_news`,
а не отдельная модель: разбирается, переводится и хранится тем же пайплайном,
поэтому у неё есть полный текст, картинки и своя страница `/news/{code}`.
Отдельная модель означала бы дублирование всего `StorePostJob` целиком.

Главная (`Main\IndexController`) фильтрует по `is_news` через скоуп `articles()`.
Остальные выборки — нет: у новости есть категория и теги (их проставляет
парсер), поэтому она равноправно попадает в `/categories/{code}`,
`/tags/{code}`, поиск, RSS и блок «похожие». Ссылку на неё обязан давать
**`Post::permalink()`**, а не `route('post.show')`: адреса разведены, и
`Post\ShowController` отвечает на «чужой» 301-м редиректом — то есть каждая
такая ссылка была бы лишним хопом. Метод зовётся `permalink()`, а не `url()`,
потому что колонка `posts.url` (адрес первоисточника) уже занята: одноимённый
метод Eloquent принимает за связь и валит `$post->url` в LogicException.
Выборкам с сужающим `select()` нужен `is_news` в списке колонок — иначе он
молча читается как `null` и все новости получают адрес статьи.

- `App\Support\NewsDigestParser` — разбор секции (без ввода-вывода, покрыт тестами)
- `App\Service\NewsImportService` — перевод и сохранение; дедуп по `posts.url` (UNIQUE)
- `php artisan news:import [url]` — вручную; в планировщике ежедневно в 07:00
- Импорт также доступен кнопкой в админке (Блог → Новости)

### Оригинал статьи (`?lang=en`)

Английский исходник лежит в `posts.content_orig` с 22.02.2026. Над статьёй —
плашка «Перевод статьи с …» (`Post::isMachineTranslated()`: сохранённый оригинал
либо успешно разобранный источник — одного `url` мало, он остаётся и у заглушек
с `parse_status = failed`, текст которых пишет админ руками). Оттуда же ссылка
на `?lang=en` — тот же адрес, то же тело страницы, другой язык статьи.

Версия оригинала отдаёт `noindex, follow` и **самоссылочный** canonical. Пара
«noindex + canonical на другой адрес» противоречива: Google документированно
переносит `noindex` на цель канонизации, то есть выбил бы из выдачи сам
перевод. `?lang=en` у поста без оригинала — 302 (не 301: оригинал появится,
если заглушку разберут повторно, а постоянный редирект браузер кэширует
навсегда). Редирект между `/posts/{code}` и `/news/{code}` сохраняет query,
иначе переключатель роняет читателя обратно в перевод.

Тело оригинала рендерится через **`Post::originalBody()`**, а не сырую колонку:
мутатор `setContentOrigAttribute` появился на пять месяцев позже самой колонки,
поэтому записи с 22.02 по 27.07.2026 хранят необработанный HTML
страницы-источника (у двух уцелел `<iframe>`). Санитайзинг на выводе не зависит
от того, прогнали ли где-то `posts:resanitize` — а команда теперь чистит обе
колонки. Заодно из оригинала вырезаются картинки с чужих доменов: их
локализует `ContentImageService`, но только для перевода, и без этого браузер
читателя ходил бы за каждой на medium/dev.to (плюс 403 хотлинка). Результат
кэшируется до следующей правки поста.

`content_orig` идёт и в поисковый индекс: термины вроде «queue worker» или
«readonly properties» в переводе остаются английскими не всегда, и запрос по
ним не находил статью, которая целиком про них. **После деплоя нужен
`php artisan scout:import "App\Models\Post"`** — Scout переиндексирует запись
только при её сохранении, иначе у всего архива в документе не будет нового
поля. `SCOUT_QUEUE=true`, так что импорт разбирает воркер очереди.

### Аналитика просмотров

`Блог → Аналитика` (`App\Filament\Pages\Analytics`) — единственный потребитель
таблицы `post_views`, кроме счётчика под заголовком статьи. Плитки трафика,
график по дням (`flowframe/laravel-trend`) и топ читаемых постов; общий фильтр
периода живёт на странице (трейт `HasFiltersForm`) и раздаётся виджетам через
`getWidgetData()`.

Виджеты лежат в `app/Filament/Analytics/Widgets`, а **не** в
`app/Filament/Widgets`: вторая директория раздаётся `discoverWidgets`, и всё из
неё Filament сам выводит на дашборде — там нет фильтра периода, а дашборд
отвечает за состояние парсинга, не за трафик. Расплата — их приходится
регистрировать в `FilamentPanelProvider` через `->livewireComponents([...])`:
первый рендер проходит и без этого, но snapshot Livewire хранит **имя**
компонента, и без алиаса в реестре любое действие внутри виджета (смена
периода, сортировка, пагинация) отвечает `ComponentNotFoundException`.
Регрессия закрыта `AnalyticsPageTest::test_analytics_widgets_are_resolvable_by_livewire_name`
— рендером страницы она не ловится.

Окна сравнения строятся одинаковой длины (`AnalyticsPeriod::previousEndsAt()`):
текущий период — это N-1 полных суток плюс прошедшая часть сегодняшнего дня,
поэтому предыдущий обрезается тем же временем суток. Иначе каждое утро при
ровном трафике плитки показывали бы падение, которого нет. Период приходит из
query-string (`#[Url]`), поэтому `AnalyticsPeriod::days()` сверяет его с белым
списком: `?filters[period]=100000` иначе означал бы скан всей `post_views`.

`post_views` — самая быстрорастущая таблица блога, отсюда: индекс на одном
`viewed_at` (составной `(post_id, viewed_at)` для фильтра без `post_id` не
работает), все плитки периода — одним проходом условной агрегации, а «всего
просмотров» (единственный неограниченный `COUNT(*)`) — из кэша на 10 минут.

### Роботы и источники переходов

Просмотром считается только заход человека. До 17.08.2026 в `post_views` шёл
любой GET страницы поста: на проде это давало 589 сессий с 311 адресов при 1212
записях — у отдельных IP до 39 разных сессий, то есть cookie не держались вовсе.
Счётчик под статьёй завышал число читателей, «Популярное» на главной и «Топ
постов» ранжировали материалы по интересу краулеров.

Отсев делает **глобальный скоуп `PostView::HUMANS_ONLY`**, а не условие в
запросах: читателей у таблицы четыре и они разнородны (`Post::viewsCount()`,
`withCount('views')` в популярных постах и в топе аналитики, `Trend` в графике).
Локальный скоуп пришлось бы дописывать в каждом — ровно так уже разъезжалось
условие «раздел с опубликованными постами». Снимать скоуп нужно там, где робот
и есть предмет разговора: дедуп визитов ищет прошлую запись
`withoutGlobalScope(PostView::HUMANS_ONLY)`, иначе помеченный краулер каждый раз
выглядел бы новым посетителем. **`PostViewsOverview` читает таблицу через
`DB::table()`, мимо Eloquent — там фильтр стоит вручную**, и это закреплено
`PostViewAudienceTest::test_overview_tiles_ignore_crawlers` (рендером страницы
не ловится).

Признак робота даёт `App\Support\BotDetector` по User-Agent. Сам заголовок **не
сохраняется** — это часть отпечатка браузера, а вся таблица построена на
неидентифицируемости посетителя; в БД идёт один бит. Детект заведомо неточен в
обе стороны, поэтому запись робота сохраняется с флагом, а не отбрасывается:
ошибку детектора можно пересмотреть, потерянную строку — нет. Подстрока `bot`
ловит `Cubot` (марка смартфонов), для таких случаев в детекторе есть список
исключений — требовать границу слова нельзя, в `Googlebot` перед `bot` стоит
буква.

От реферера хранится только хост (`referer_host`): путь и query чужой страницы
— чужие данные, а на вопрос «откуда трафик» отвечает домен. Хост нормализуется
`PostViewService::normalizeHost()` — нижний регистр, срез `www.` и завершающей
точки, проверка алфавита: `parse_url()` отдаёт хостом и `<script>alert(1)<`, а
`habr.com.` иначе встал бы в отчёте отдельной строкой, то есть разбить топ
источников мог бы кто угодно одним заголовком.

**Переход внутри сайта пишется собственным хостом, а не `NULL`.** Три разные
вещи обязаны различаться: `NULL` — реферера не было (прямой заход), свой хост —
навигация по блогу, чужой — источник. Свалив первые два в `NULL`, отчёт называл
бы прямыми заходами всю внутреннюю перелинковку, а это большинство просмотров;
восстановить разделение потом нечем. Виджет показывает их отдельными строками.

Метки `utm_*` приходят из адресной строки, то есть пишет их кто угодно: отсюда
обрезка по длине, отказ от нескалярных значений (`?utm_source[]=a` иначе ронял
бы показ статьи) и ограничение алфавита. Обратная сторона: метка — сама по себе
идентификатор, и ссылка с уникальным `utm_campaign`, отправленная конкретному
человеку, связывает его с `ip_hash`. HMAC от этого не спасает, поэтому метка
хранится только как короткий идентификатор кампании.

Граница `PostView::ATTRIBUTION_SINCE` (17.08.2026, день выката) делит данные на
«до» и «после», и её знают оба потребителя. У ранних записей `referer_host = NULL`
значит «неизвестно», а не «прямой заход», поэтому **виджет источников их не
считает** — на периоде в квартал они бы весь отчёт и составили. И наоборот:
разметка задним числом (`php artisan post-views:mark-bots`, признак — сколько
разных сессий пришло с одного IP, есть `--dry-run` и `--reset`) работает
**только до этой даты**. Без верхней границы эвристика съедала бы живых
читателей — четыре сессии с адреса даёт обычный офис за NAT, — а `--reset`
снимал бы флаг и с записей, размеченных по User-Agent, восстановить которые
нечем.

### Пустые категории и теги

Ссылаться можно только на раздел, в котором есть хоть один опубликованный пост:
`Category::hasPublishedPosts()` / `Tag::hasPublishedPosts()` — один скоуп на обе
карты сайта, оба листинга и сайдбар главной. Раньше условие стояло дословно в
шести местах и разъехалось: `sitemap.xml` фильтровал, а `/sitemap`,
`/categories` и `/tags` перечисляли `Category::all()` — и вели на разделы с
нулём материалов. Теги страдают сильнее категорий: их проставляет
`TagDetectorService` прямо при парсинге, задолго до публикации.

Сама страница пустого раздела остаётся доступной (200), но отдаёт
`robots: noindex, follow` — адреса, которые Вебмастер уже проиндексировал,
иначе из выдачи не уйдут. Не 404: посты в разделе появятся при следующей
публикации, а отданная единожды 404 выбьет адрес насовсем. Условие —
`$posts->isEmpty()`, а не `total() === 0`: последнее считает пустыми только
разделы без постов вообще и оставляет открытым `?page=99` наполненной
категории — нулевой список, канонический сам на себя.

### Models & Relationships

- `Post` — `belongsToMany(Tag)`, `belongsTo(Category)`, `hasMany(Comment)`, `hasMany(PostLike)`. Uses `SoftDeletes` and `Searchable` (Laravel Scout → Meilisearch). `permalink()` — публичный адрес с учётом `is_news`
- `User` — roles: `ROLE_ADMIN = 0`, `ROLE_READER = 1`. Implements `FilamentUser` for Filament access
- `Release` — stores source URLs for the scraper pipeline

### Broadcasting

Uses **Laravel Reverb** (WebSocket server). Events: `UserNotification`, `PostLiked`. Channel configuration in `routes/channels.php`.

### Search

Laravel Scout with **Meilisearch** backend (container on port 7720→7700). Meilisearch master key configured via `MEILISEARCH_KEY` env var.

### Frontend

Blade templates for the public site; the admin UI is Filament's own. **Livewire 3** powers Filament. Vite for asset bundling.

Демо-страница `/counter` из туториала Livewire (`app/Livewire/Counter`, макет `components/layouts/app.blade.php`) удалена 13.08.2026: она отдавала 200, не была закрыта в `robots.txt` и рендерила `<head>` без описания и без `robots` — единственная такая страница на сайте, из-за которой Яндекс.Вебмастер продолжал показывать «Отсутствуют метатеги &lt;Description&gt;» уже после общего фикса метатегов. Описание гарантируют только макеты (`layouts.main` через композер в `AppServiceProvider`, `layouts.app` через фолбэк), поэтому любая страница мимо них снова окажется пустой.

Вместе с демо-страницей удалён `components/layouts/app.blade.php` — макет full-page компонентов Livewire по умолчанию. Своих full-page компонентов в проекте нет, а если появится, Livewire упадёт с «View not found»: это нужное поведение, макет надо завести заново с метатегами, а не восстанавливать `livewire:publish`. В `SeoFeedSitemapTest` адреса без параметров теперь берутся из таблицы маршрутов (страницы с параметрами по-прежнему перечислены поимённо), так что следующая такая страница упадёт в тестах.

### Deployment

Laravel Envoy (`Envoy.blade.php`) deploys to production via SSH using a timestamped releases strategy (keeps last 5 releases). Requires env vars: `DEPLOY_USER`, `DEPLOY_USER_KEY`, `DEPLOY_SERVER`, `DEPLOY_REPOSITORY`, `DEPLOY_PATH`.

After `envoy run deploy`, reload Apache — mod_php caches realpath and keeps serving the previous release for about two minutes otherwise.

### Long-running services on production (outside the deploy)

Three things run alongside the app and are **not** recreated by `envoy run deploy`:

- **Reverb** (WebSocket) — supervisor program `blog-reverb`, listens on `127.0.0.1:8080`, proxied publicly by Apache at `/app`. The deploy does restart it, otherwise it would keep executing code from a release that gets purged.
- **FlareSolverr** (headless browser) — Docker container, `127.0.0.1:8191`, used by the parser to get past antibot challenges that require JavaScript (`config/releases.php` → `challenge_solver_url`). Recreate with `./vendor/bin/envoy run challenge-solver`; the deploy deliberately leaves it alone since it survives reboots via `--restart=unless-stopped` and recreating would drop the browser session.
- **Cron планировщика** — `/etc/cron.d/blog-scheduler`, ставится командой `./vendor/bin/envoy run scheduler-cron`. Без него `app/Console/Kernel.php` не выполняется вообще. Именно так и было до 11.08.2026: расписание с `backup:run` лежало в коде с 3 августа, а бэкапов физически не существовало. Запись от `www-data` — под тем же пользователем работают Apache и `queue:work`; под root артефакты в `storage/` получали бы `root:root` и отбирали запись у веба.

### Бэкапы

`spatie/laravel-backup`, расписание в `app/Console/Kernel.php`: `backup:clean` в 01:00, `backup:run` в 01:30, `backup:monitor` в 06:00 — все под `environments(['production'])`.

- Архив шифруется AES-256 паролем из `BACKUP_ARCHIVE_PASSWORD`. **Пароль обязан храниться вне сервера**: он лежит в том же `.env`, который пропадёт вместе с машиной.
- `.env` намеренно исключён из архива — восстановление двухсоставное: архив + `.env` из менеджера паролей.
- `storage_path('app/public')` включён в `include` явно. На проде `storage/app` — симлинк в `shared/`, а `follow_links = false`: без явного пути картинки постов (единственное, чего нет в git) в архив не попадали.
- Каталог архивов зовётся `blog` (`BACKUP_NAME`), а не `APP_NAME`: пробелы и кириллица в пути ломают ручной `rsync`/`scp` при восстановлении.
- Системный `unzip` эти архивы распаковать не может («unsupported compression or encoding» — он не знает WinZip AES). Восстанавливать через `7z x` или PHP `ZipArchive::setPassword()`.

`--memory=900m` for FlareSolverr is not arbitrary: with 512m Chrome fails to finish the challenge and the service returns `Timeout after 60.0 seconds`. The peak is only needed while solving — at rest the container holds ~110 MB.

`composer.json` pins `config.platform.php` to the production PHP version. Raise it only together with PHP on the server, otherwise the lock resolves against the local (newer) PHP and produces a set of packages that cannot be installed on production.
