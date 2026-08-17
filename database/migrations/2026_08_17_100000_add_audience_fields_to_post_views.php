<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * post_views считала просмотром любой GET страницы поста, включая обход
 * краулерами. На 17.08.2026 это 589 сессий на 311 IP при 1212 записях: у
 * отдельных адресов до 39 разных сессий, то есть каждый их запрос приходил без
 * cookie — подпись робота, а не читателя. Распределение по часам подтверждало:
 * пики приходились на 03–05 МСК, когда русскоязычная аудитория спит.
 *
 * Из-за этого счётчик под статьёй завышал число читателей, «Топ постов»
 * ранжировал статьи по интересу поисковых роботов, а «уникальные читатели» в
 * аналитике считали каждый заход робота новым человеком.
 *
 * Отличать одних от других можно только по User-Agent, а он не сохранялся.
 * Сам заголовок не пишем: это часть отпечатка браузера, а вся таблица
 * построена на том, чтобы посетитель не был идентифицируем (см.
 * App\Service\PostViewService::pseudonymize). Пишем только вывод
 * App\Support\BotDetector — один бит.
 *
 * referer_host и utm_* отвечают на вопрос «откуда пришли». Хост, а не полный
 * URL реферера: путь и query чужой страницы могут нести чужие персональные
 * данные, а для источника трафика достаточно домена.
 *
 * Исторические записи остаются с is_bot = false: задним числом робот
 * определяется только косвенно, этим занимается отдельная команда
 * post-views:mark-bots, которую видно в истории и можно откатить.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('session_hash');
            $table->string('referer_host', 255)->nullable()->after('is_bot');
            $table->string('utm_source', 100)->nullable()->after('referer_host');
            $table->string('utm_medium', 100)->nullable()->after('utm_source');
            $table->string('utm_campaign', 100)->nullable()->after('utm_medium');

            // Все выборки аналитики теперь отсекают роботов и режут период по
            // viewed_at. Индекса на одном viewed_at для этого мало: с ведущим
            // is_bot диапазон читается уже отфильтрованным.
            $table->index(['is_bot', 'viewed_at'], 'post_views_human_viewed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->dropIndex('post_views_human_viewed_at_idx');
            $table->dropColumn(['is_bot', 'referer_host', 'utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
