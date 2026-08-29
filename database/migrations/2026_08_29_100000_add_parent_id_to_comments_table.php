<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ответы на комментарии. nullOnDelete, а не cascade: при удалении
     * комментария его ответы остаются самостоятельными сообщениями ветки
     * (удаление у нас мягкое, FK на живую запись не сработает, но для
     * отложенного force-удаления поведение уже определено здесь).
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('post_id')
                ->constrained('comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
