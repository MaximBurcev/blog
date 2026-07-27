<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отдельный флаг модерации, независимый от soft delete: снять комментарий
     * с публикации (скрыть с публичной страницы) и вернуть обратно — один клик
     * в админке, без возни с восстановлением из удалённых.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('published')->default(true)->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
