<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * post_views хранила session_id в открытом виде — это в точности значение
 * cookie laravel_session, то есть bearer-токен живой сессии. Он писался на
 * каждый просмотр поста и уезжал в дампы БД, бэкапы и telescope_entries:
 * чтение таблицы означало захват сессий активных пользователей, включая админа.
 * ip_hash был несолёным sha256 — весь диапазон IPv4 перебирается за минуты,
 * то есть псевдонимизация была фиктивной.
 *
 * Оба идентификатора переводятся на HMAC с ключом приложения (см.
 * App\Service\PostViewService). Старые значения несопоставимы с новыми, поэтому
 * очищаются: сами строки остаются и продолжают считаться просмотрами, теряется
 * только дедуп по визитам старше этой миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });

        Schema::table('post_views', function (Blueprint $table) {
            $table->string('session_hash', 64)->nullable()->after('ip_hash');
        });

        DB::table('post_views')->update(['ip_hash' => null]);
    }

    public function down(): void
    {
        Schema::table('post_views', function (Blueprint $table) {
            $table->dropColumn('session_hash');
        });

        Schema::table('post_views', function (Blueprint $table) {
            $table->string('session_id')->nullable()->after('ip_hash');
        });
    }
};
