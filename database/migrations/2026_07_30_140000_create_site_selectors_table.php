<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSS-селектор блока с текстом статьи задавался только в
 * config/releases.php ('domain_selectors'), то есть добавление нового
 * источника требовало правки кода и деплоя. Переносим правила в БД, чтобы
 * их можно было заводить из админки; конфиг остаётся фолбэком для доменов,
 * которых нет в таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_selectors', function (Blueprint $table) {
            $table->id();
            // Подстрока хоста ('dev.to', 'blog.jetbrains.com') — сопоставление
            // идёт через str_contains, как раньше в конфиге.
            $table->string('domain')->unique();
            $table->string('content_selector');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $rows = [];
        foreach (config('releases.domain_selectors', []) as $domain => $selector) {
            $rows[] = [
                'domain' => $domain,
                'content_selector' => $selector,
                'note' => 'Перенесено из config/releases.php',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('site_selectors')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_selectors');
    }
};
