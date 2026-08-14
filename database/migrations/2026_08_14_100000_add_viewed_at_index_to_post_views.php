<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс под аналитику просмотров.
 *
 * У post_views есть только составной (post_id, viewed_at) — он закрывает дедуп
 * («видел ли этот посетитель конкретный пост»), но не запросы страницы
 * аналитики: там фильтр идёт по одному viewed_at, без post_id, а по левой
 * колонке составного индекса такой запрос не проходит. То есть и график по
 * дням, и плитки-счётчики читали бы таблицу целиком — а post_views растёт на
 * строку с каждого уникального просмотра, то есть быстрее всех остальных
 * таблиц блога вместе взятых.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->index('viewed_at', 'post_views_viewed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->dropIndex('post_views_viewed_at_idx');
        });
    }
};
