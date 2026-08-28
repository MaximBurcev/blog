<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Составной индекс под страницу категории.
 *
 * Category\ShowController фильтрует по category_id + published и сортирует
 * по created_at, а существующий posts_published_created_idx имеет ведущую
 * published — выборку по категории он не сужает, и листинг раздела читал
 * все опубликованные посты с последующей фильтрацией. Порядок колонок тот
 * же, что у comments_post_published_created_idx: равенство, равенство,
 * сортировка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['category_id', 'published', 'created_at'], 'posts_category_published_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_category_published_created_idx');
        });
    }
};
