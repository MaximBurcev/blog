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

### Models & Relationships

- `Post` — `belongsToMany(Tag)`, `belongsTo(Category)`, `hasMany(Comment)`, `hasMany(PostLike)`. Uses `SoftDeletes` and `Searchable` (Laravel Scout → Meilisearch)
- `User` — roles: `ROLE_ADMIN = 0`, `ROLE_READER = 1`. Implements `FilamentUser` for Filament access
- `Release` — stores source URLs for the scraper pipeline

### Broadcasting

Uses **Laravel Reverb** (WebSocket server). Events: `UserNotification`, `PostLiked`. Channel configuration in `routes/channels.php`.

### Search

Laravel Scout with **Meilisearch** backend (container on port 7720→7700). Meilisearch master key configured via `MEILISEARCH_KEY` env var.

### Frontend

Blade templates for the public site; the admin UI is Filament's own. **Livewire 3** powers Filament and the standalone `app/Livewire/Counter`. Vite for asset bundling.

### Deployment

Laravel Envoy (`Envoy.blade.php`) deploys to production via SSH using a timestamped releases strategy (keeps last 5 releases). Requires env vars: `DEPLOY_USER`, `DEPLOY_USER_KEY`, `DEPLOY_SERVER`, `DEPLOY_REPOSITORY`, `DEPLOY_PATH`.

After `envoy run deploy`, reload Apache — mod_php caches realpath and keeps serving the previous release for about two minutes otherwise.

### Long-running services on production (outside the deploy)

Two things run alongside the app and are **not** recreated by `envoy run deploy`:

- **Reverb** (WebSocket) — supervisor program `blog-reverb`, listens on `127.0.0.1:8080`, proxied publicly by Apache at `/app`. The deploy does restart it, otherwise it would keep executing code from a release that gets purged.
- **FlareSolverr** (headless browser) — Docker container, `127.0.0.1:8191`, used by the parser to get past antibot challenges that require JavaScript (`config/releases.php` → `challenge_solver_url`). Recreate with `./vendor/bin/envoy run challenge-solver`; the deploy deliberately leaves it alone since it survives reboots via `--restart=unless-stopped` and recreating would drop the browser session.

`--memory=900m` for FlareSolverr is not arbitrary: with 512m Chrome fails to finish the challenge and the service returns `Timeout after 60.0 seconds`. The peak is only needed while solving — at rest the container holds ~110 MB.

`composer.json` pins `config.platform.php` to the production PHP version. Raise it only together with PHP on the server, otherwise the lock resolves against the local (newer) PHP and produces a set of packages that cannot be installed on production.
