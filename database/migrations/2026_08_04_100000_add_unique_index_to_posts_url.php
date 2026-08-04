<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * url — единственный ключ дедупликации скрейпленной статьи: PostService::store()
 * делает по нему updateOrCreate. Без UNIQUE эта операция неатомарна — две
 * джобы по одной ссылке успевали обе не найти пост и обе его создать.
 *
 * Пустые строки приводим к NULL: в MySQL несколько NULL в уникальном индексе
 * разрешены, а несколько '' — уже нарушение. Семантически это одно и то же —
 * «источника нет» (пост создан руками), и PostService оба случая проверяет
 * через empty(), так что ветка updateOrCreate/create не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('posts')->where('url', '')->update(['url' => null]);

        $duplicates = DB::table('posts')
            ->whereNotNull('url')
            ->select('url')
            ->groupBy('url')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicates > 0) {
            // Осознанно не удаляем посты внутри миграции: это необратимо и
            // должно происходить под присмотром, с возможностью посмотреть план.
            throw new RuntimeException(
                "В posts осталось {$duplicates} групп дублей по url — UNIQUE не накатить. ".
                'Запустите «php artisan posts:dedupe-urls», проверьте план, затем повторите с --force и накатите миграцию заново.'
            );
        }

        Schema::table('posts', function (Blueprint $table) {
            // Обычный индекс из 2026_07_27_000002 перекрывается уникальным.
            $table->dropIndex('posts_url_idx');
            $table->unique('url', 'posts_url_unique');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique('posts_url_unique');
            $table->index('url', 'posts_url_idx');
        });
    }
};
