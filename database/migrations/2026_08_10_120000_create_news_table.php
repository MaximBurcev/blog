<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новости из секции «News and Announcements» дайджеста PHP Weekly.
 *
 * Отдельная таблица, а не категория постов: форма данных другая — нет
 * полного текста, нет content_orig, parse_status и WebP-вариантов, зато
 * всегда есть ссылка на первоисточник. Смешивать их с постами означало бы
 * исключать новости из общей ленты, поиска, RSS и sitemap в каждом запросе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();

            // Ключ дедупликации: повторный импорт того же дайджеста (или
            // соседнего выпуска, где новость повторилась) не должен плодить
            // дубли. UNIQUE, а не только updateOrCreate, — на случай гонки
            // двух воркеров, как это уже было с posts.url.
            // 255, как у posts.url: varchar(2048) в utf8mb4 — это 8192 байта,
            // больше лимита InnoDB на ключ (3072), и UNIQUE на такой колонке
            // не создаётся. Ссылки из дайджеста в 255 символов укладываются.
            $table->string('url')->unique();

            $table->string('title');
            $table->string('title_orig');
            $table->text('summary');
            $table->text('summary_orig');

            // Домен первоисточника: показывается в ленте, чтобы читатель
            // видел, куда ведёт ссылка, до клика.
            $table->string('source_host')->nullable();

            $table->boolean('published')->default(true);

            // Часть блоков могла остаться непереведённой (лимиты Google) —
            // тот же сигнал, что и у постов, для ревью из админки.
            $table->boolean('translation_incomplete')->default(false);

            $table->timestamps();

            // Лента: свежие опубликованные сверху.
            $table->index(['published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
