<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сколько раз новостной импорт уже пробовал разобрать эту ссылку.
 *
 * NewsImportService намеренно не пропускает заглушки (parse_status = failed):
 * сбой мог быть временным — антибот, таймаут, лежащий источник, — и ссылка не
 * должна пропасть из-за одной неудачи. Потолка у этих попыток не было, а часть
 * ссылок новостной секции дайджеста — ролики YouTube, главная php.net и
 * страницы релизов GitHub, где статьи не существует в принципе. Разбор их не
 * починится никогда, и «второй шанс» превращался в вечный цикл: каждое утро
 * заново скачать страницу, заново не найти тело статьи, заново сохранить
 * заглушку.
 *
 * Колонка нужна ровно для того, чтобы отличить «не получилось пока» от «не
 * получится никогда»: временная беда проходит за пару попыток, постоянная —
 * нет.
 *
 * Счётчик ведёт NewsImportService — тот, кто повторы и устраивает, а не
 * StorePostJob: джоба про свой запуск и предыдущих не помнит, ей пришлось бы
 * читать пост из БД ради инкремента. Сбрасывать счётчик не нужно: как только
 * parse_status перестаёт быть failed, импорт пропускает запись первой же
 * проверкой и до счётчика не доходит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // smallInteger, а не tinyInteger: потолок задаётся конфигом, и
            // упереться в 255 из-за типа колонки было бы глупым сюрпризом.
            $table->unsignedSmallInteger('parse_attempts')->default(0)->after('parsed_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('parse_attempts');
        });
    }
};
