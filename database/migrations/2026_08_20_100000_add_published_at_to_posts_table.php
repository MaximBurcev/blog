<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Момент публикации поста нигде не фиксировался.
 *
 * У posts есть только булев published и created_at — дата, когда парсер завёл
 * запись, а не когда её показали читателю. Между этими событиями у блога
 * проходят недели: на 20.08.2026 в очереди 111 разобранных постов при 28
 * опубликованных, и самые старые ждут с мая.
 *
 * Из-за этого нельзя ответить на вопрос «сколько мы публикуем в неделю» —
 * то есть измерить ровно то, что является главным узким местом. Любой график
 * публикаций строился бы по created_at и показывал бы темп парсера, выдавая
 * его за темп редактора.
 *
 * Историческим записям проставляется updated_at: точной даты публикации для них
 * не существует, а последнее изменение — ближайшее доступное приближение (для
 * большинства это и есть момент, когда сняли галочку черновика). Приближение
 * помечено в коде: PublishingPace считает только те периоды, что начались после
 * этой миграции, чтобы прошлые недели не выглядели достоверными.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('published');

            // Индекс под единственный запрос, который к колонке ходит:
            // «сколько опубликовано за период».
            $table->index('published_at', 'posts_published_at_idx');
        });

        DB::table('posts')
            ->where('published', 1)
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_published_at_idx');
            $table->dropColumn('published_at');
        });
    }
};
