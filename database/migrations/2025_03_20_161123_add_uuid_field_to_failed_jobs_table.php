<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Базовая 2019_08_19_000000_create_failed_jobs_table уже создаёт
     * uuid (unique, not null). Пропускаем, если колонка уже есть, чтобы
     * migrate:fresh не падал на "Duplicate column".
     */
    public function up(): void
    {
        if (! Schema::hasColumn('failed_jobs', 'uuid')) {
            Schema::table('failed_jobs', function (Blueprint $table) {
                $table->string('uuid')->after('id')->nullable()->unique();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            //
        });
    }
};
