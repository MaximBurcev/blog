<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

# Laravel Blog

Движок блога на Laravel: скрейпинг постов из внешних источников (по CSS-селекторам), автоперевод на русский (текст + OCR картинок), автоматическое определение категорий/тегов, полнотекстовый поиск (Meilisearch), лайки/комментарии с модерацией, RSS/sitemap, email- и broadcast-уведомления.

Два административных интерфейса сосуществуют в проекте:
- **Custom Admin** (`/admin`) — AdminLTE + Blade, single-action контроллеры.
- **Filament** (`/filament`) — современная админка на Filament 3.

Подробное описание архитектуры, паттернов и конвенций проекта — в [CLAUDE.md](./CLAUDE.md).

## Запуск (Laravel Sail)

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

Сайт будет доступен на `http://localhost` (порт настраивается через `APP_PORT` в `.env`).

## Тесты и линтеры

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail pint
./vendor/bin/sail php vendor/bin/phpstan analyse
```

## Security

Если вы обнаружили уязвимость в этом проекте — не создавайте публичный issue, свяжитесь с мейнтейнером напрямую.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
