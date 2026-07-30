<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раньше StorePostJob при любой неудаче (пустой ответ, антибот-заглушка,
 * не найден заголовок/контент) просто писал warning в лог и выходил — в
 * админке пост не появлялся вообще, и понять, что ссылка из релиза
 * отвалилась и почему, можно было только грепая laravel.log.
 *
 * Теперь пост создаётся всегда, а результат парсинга хранится прямо на нём.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('parse_status')->nullable()->after('selector');
            $table->text('parse_error')->nullable()->after('parse_status');
            $table->timestamp('parsed_at')->nullable()->after('parse_error');

            // Основной сценарий выборки в админке — «покажи всё, что
            // отвалилось при парсинге».
            $table->index('parse_status', 'posts_parse_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_parse_status_idx');
            $table->dropColumn(['parse_status', 'parse_error', 'parsed_at']);
        });
    }
};
