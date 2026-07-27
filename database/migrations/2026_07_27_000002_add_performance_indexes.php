<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * posts.code/published/url, categories.code, tags.code не имели индекса
 * с момента создания таблиц — ShowController/sitemap/RSS ищут по code,
 * published фильтрует почти каждую публичную выборку, url — точка входа
 * для updateOrCreate() в PostService (защита от дублей при retry джобы).
 * Не unique — в данных уже есть дубли code/url у старых записей (см.
 * docs/blog-improvements-plan.md), дедупликация — отдельная задача.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index('code', 'posts_code_idx');
            $table->index('published', 'posts_published_idx');
            $table->index('url', 'posts_url_idx');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->index('code', 'categories_code_idx');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->index('code', 'tags_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_code_idx');
            $table->dropIndex('posts_published_idx');
            $table->dropIndex('posts_url_idx');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_code_idx');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex('tags_code_idx');
        });
    }
};
