<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Новости — это те же посты, просто помеченные флагом.
 *
 * Отдельная модель News с собственным пайплайном означала бы дублирование
 * всего, что накоплено вокруг StorePostJob: фетч с SSRF-проверкой и
 * IP-пиннингом, обход antibot (RSS + headless), скачивание картинок,
 * перевод, ретраи, пост-заглушки при сбое, разрешение селектора по домену.
 * Каждая правка там делалась бы дважды. Флаг даёт «функционал как у постов»
 * буквально — это один и тот же код.
 *
 * Данные из промежуточной таблицы news не переносим: там лежали только
 * заголовок и краткое описание из дайджеста, полного текста не было вовсе.
 * Те же ссылки заново разберёт news:import уже через StorePostJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('is_news')->default(false)->after('published');

            // Лента новостей и лента статей — два взаимоисключающих списка,
            // оба сортируются по дате.
            $table->index(['is_news', 'published', 'created_at'], 'posts_news_listing_index');
        });

        // Ссылки, уже импортированные как короткие новости, помечаем — если
        // такой пост успели разобрать, он сразу окажется в нужном разделе.
        if (Schema::hasTable('news')) {
            $urls = DB::table('news')->pluck('url')->all();

            if ($urls !== []) {
                Post::withoutSyncingToSearch(
                    fn () => DB::table('posts')->whereIn('url', $urls)->update(['is_news' => true])
                );
            }
        }

        Schema::dropIfExists('news');
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_news_listing_index');
            $table->dropColumn('is_news');
        });
    }
};
