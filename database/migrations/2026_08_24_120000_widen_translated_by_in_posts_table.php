<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * translated_by стал вмещать имя модели, а не только имя движка.
 *
 * Колонка заводилась под значения вроде gemini / google / none — шесть
 * символов, и varchar(32) был с запасом. С 24.08.2026 туда едет имя модели:
 * квота у Google считается по модели, поэтому и предохранитель, и пометка о
 * переводе обязаны различать gemini-3.6-flash и gemini-3.5-flash-lite.
 *
 * 64 — как у llm_calls.model, чтобы две колонки об одном и том же не
 * расходились. Запас не теоретический: у превью-моделей Google имена вида
 * gemini-2.5-flash-lite-preview-06-17 — 35 символов, и на strict-режиме
 * (config/database.php) первый же пост с такой моделью падал бы с «Data too
 * long», то есть уходил в заглушку вместо статьи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('translated_by', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('translated_by', 32)->nullable()->change();
        });
    }
};
