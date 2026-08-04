<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под фактические запросы страниц.
 *
 * comments не имела ни одного индекса, кроме PRIMARY, хотя лента поста
 * фильтрует по post_id + published (+ deleted_at от SoftDeletes) и сортирует
 * по created_at — то есть каждый показ статьи означал полный скан таблицы.
 *
 * У posts был только одноколоночный published, поэтому листинги
 * (WHERE published ORDER BY created_at DESC) шли через filesort, а виджет
 * админки сортировал по неиндексированному parsed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // Порядок колонок под запрос ленты: равенство, равенство, сортировка.
            $table->index(['post_id', 'published', 'created_at'], 'comments_post_published_created_idx');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index(['published', 'created_at'], 'posts_published_created_idx');
            $table->index('parsed_at', 'posts_parsed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_post_published_created_idx');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_published_created_idx');
            $table->dropIndex('posts_parsed_at_idx');
        });
    }
};
