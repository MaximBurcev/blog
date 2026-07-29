<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чистим накопившиеся дубли пар (post_id, tag_id), оставляя строку с
        // минимальным id. Причина дублей — PostService::store() вешал теги через
        // attach() при идемпотентном updateOrCreate по url (повторный
        // StorePostJob для существующего поста). Код переведён на sync().
        DB::statement('
            DELETE t1 FROM post_tags t1
            JOIN post_tags t2
              ON t1.post_id = t2.post_id
             AND t1.tag_id = t2.tag_id
             AND t1.id > t2.id
        ');

        // Уникальный индекс как страховка: любой путь записи (sync/attach,
        // Livewire, ручное) больше не сможет создать дубль пары.
        Schema::table('post_tags', function (Blueprint $table) {
            $table->unique(['post_id', 'tag_id'], 'post_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::table('post_tags', function (Blueprint $table) {
            $table->dropUnique('post_tag_unique');
        });
    }
};
