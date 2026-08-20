<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чем переведена статья.
 *
 * С 20.08.2026 движков два: LLM (Gemini) и прежний скрейпер
 * translate.google.com, который остался запасным на случай, когда LLM
 * недоступна — сеть, квота, региональные ограничения. Подмена происходит молча
 * и для читателя незаметна, а качество у движков разное.
 *
 * Без этой колонки деградация невидима. Ровно так уже вышло с OCR: неудачный
 * запуск tesseract полгода выглядел как «на картинке нет текста», и перевод
 * текста на картинках не сработал ни разу — 124 записи и ноль перерисовок.
 * Здесь та же ловушка, поэтому имя сработавшего движка пишется в БД: по нему
 * видно, какие статьи стоит перевести заново, когда основной движок починится.
 *
 * NULL у исторических записей — «неизвестно»: до появления колонки всё
 * переводил скрейпер, но проставлять это задним числом значит выдавать
 * догадку за факт, а отличить переведённое от заглушек с parse_status = failed
 * по одному этому полю всё равно нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('translated_by', 32)->nullable()->after('translation_incomplete');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('translated_by');
        });
    }
};
