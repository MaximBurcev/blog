<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Дубликат 2023_09_04_165418_add_code_to_categories_table — та же
     * колонка. На средах, где обе миграции уже отмечены как выполненные,
     * колонка добавлена первой из них; здесь просто пропускаем, если она
     * уже есть, чтобы migrate:fresh не падал на "Duplicate column".
     */
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'code')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Колонку удаляет 2023_09_04_165418_add_code_to_categories_table —
        // не трогаем её здесь повторно
    }
};
