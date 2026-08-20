<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что читатели ищут на сайте.
 *
 * Поиск работал с самого начала, но не оставлял следов: единственный источник
 * тем для блога, где читатель прямым текстом говорит, чего ему не хватает,
 * пропадал впустую. Особенно ценны запросы без результатов — это готовый
 * список статей, которых нет.
 *
 * Сознательно НЕ храним, кто искал: ни IP, ни хэша сессии, ни User-Agent.
 * Поисковый запрос куда чувствительнее адреса страницы, а на вопрос «о чём
 * писать» отвечает сам текст запроса, без привязки к человеку. По той же
 * причине здесь нет и внешнего ключа на users.
 *
 * Но полной неcвязуемости это не даёт, и обещать её было бы нечестно: рядом
 * лежит post_views с session_hash и временем просмотра, а клик по результату
 * происходит через секунды после запроса — при полусотне визитов в день
 * сопоставление по времени почти однозначно. Плюс тот же текст виден в
 * access-логах веб-сервера рядом с IP.
 *
 * Поэтому: время огрубляется до часа (отчёты строятся по периодам от недели,
 * секундная точность им не нужна вовсе), а журнал чистится по расписанию —
 * search-queries:prune, полгода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();

            // Нормализованный текст (нижний регистр, схлопнутые пробелы) —
            // иначе «Laravel», «laravel» и «laravel  » станут тремя строками
            // отчёта вместо одной.
            $table->string('query', 191);

            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->nullable();

            // Отчёт всегда ограничен периодом и группирует по тексту.
            $table->index(['created_at', 'query'], 'search_queries_period_idx');
            // Отдельный индекс под «что искали и не нашли»: строк с нулём
            // результатов заметно меньше, и выборка по ним самая частая.
            $table->index(['results_count', 'created_at'], 'search_queries_empty_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
