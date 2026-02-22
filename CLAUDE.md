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

### Two Admin Interfaces

The project has **two separate admin panels** that coexist:

1. **Custom Admin** at `/admin` — built with AdminLTE + Blade templates, protected by `auth` + `admin` middleware. Controllers are single-action classes under `app/Http/Controllers/Admin/`.

2. **Filament Panel** at `/filament` — modern admin built with Filament 3, auto-discovers resources in `app/Filament/Resources/`. Configured in `app/Providers/Filament/FilamentPanelProvider.php`.

### Dual Database Connections

The app uses **two MySQL databases** (`config/database.php`):
- `mysql` — default connection (users, categories, tags, comments, releases)
- `secondary` — used specifically by the `Post` model (`protected $connection = 'secondary'`)

Configure via env vars: `DB_*` (primary) and `DB_SECONDARY_*` (secondary).

### Controller Pattern

All controllers are single-action classes — each HTTP action (index, show, store, etc.) has its own dedicated controller class. Example: `Admin/Post/StoreController.php`, `Admin/Post/UpdateController.php`.

### Services (`app/Service/`)

- **PostService** — creates/updates posts, handles image upload to `storage/public/images`, optionally runs translation via `TranslateService`
- **ReleaseService** — stores Release URLs, parses external pages with CSS selectors (via Symfony DomCrawler), dispatches `StorePostJob` for each found link. Configurable via `config/releases.php`
- **ContentImageService** — downloads external images referenced in post content, saves them locally to `storage/public/images/content/`
- **TranslateService** — wraps Google Translate for post content translation

### Async Jobs (`app/Jobs/`)

- **StorePostJob** — fetches an external URL, extracts content by CSS selector, translates text nodes to Russian via Google Translate (skipping `<code>` tags), downloads images via `ContentImageService`, then calls `PostService::store()`
- **ParseLinksJob** — parses links from a release URL
- **StoreUserJob** — async user creation

### Models & Relationships

- `Post` (secondary DB) — `belongsToMany(Tag)`, `belongsTo(Category)`, `hasMany(Comment)`, `hasMany(PostLike)`. Uses `SoftDeletes` and `Searchable` (Laravel Scout → Meilisearch)
- `User` — roles: `ROLE_ADMIN = 0`, `ROLE_READER = 1`. Implements `FilamentUser` for Filament access
- `Release` — stores source URLs for the scraper pipeline

### Broadcasting

Uses **Laravel Reverb** (WebSocket server). Events: `UserNotification`, `PostLiked`. Channel configuration in `routes/channels.php`.

### Search

Laravel Scout with **Meilisearch** backend (container on port 7720→7700). Meilisearch master key configured via `MEILISEARCH_KEY` env var.

### Frontend

Blade templates with **AdminLTE** (admin), standard layouts (public). **Livewire 3** components in `app/Livewire/` for interactive elements (`Counter`, `UsersTable`, admin forms). Vite for asset bundling.

### Deployment

Laravel Envoy (`Envoy.blade.php`) deploys to production via SSH using a timestamped releases strategy (keeps last 5 releases). Requires env vars: `DEPLOY_USER`, `DEPLOY_USER_KEY`, `DEPLOY_SERVER`, `DEPLOY_REPOSITORY`, `DEPLOY_PATH`.
