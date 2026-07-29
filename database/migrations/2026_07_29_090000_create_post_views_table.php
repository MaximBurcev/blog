<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            // IP храним хэшем (sha256), а не в открытом виде — для дедупа
            // накрутки этого достаточно, а персональные данные не оседают в БД.
            $table->string('ip_hash', 64)->nullable();
            $table->string('session_id')->nullable();
            $table->timestamp('viewed_at');

            // Дедуп-запрос фильтрует по post_id + viewed_at, затем по
            // session_id/ip_hash; агрегат «популярное за период» — по viewed_at.
            $table->index(['post_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_views');
    }
};
