<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заводит в site_selectors правила разбора источников JavaScript Weekly.
 *
 * Сами значения лежат и в config/releases.php, но конфиг после
 * create_site_selectors_table — фолбэк «для доменов, которых нет в
 * таблице»: правило оттуда нельзя посмотреть и поправить в админке, а
 * съехавший у источника селектор чинится правкой кода, деплоем и
 * перезагрузкой Apache. Первичное наполнение таблицы делает миграция —
 * ровно так же, как это было сделано для PHP-источников.
 *
 * insertOrIgnore, а не insert: домен уникален, и правило, заведённое
 * админом раньше миграции, важнее нашего замера — перезаписав его, мы
 * молча отменили бы чужую правку.
 */
return new class extends Migration
{
    /**
     * Замер 26.08.2026 на выпуске 799. В примечании — размер HTML блока и
     * число запросов к модели на тело статьи: по нему видно, во что
     * обходится источник, когда придётся решать, оставлять ли его.
     */
    private const SELECTORS = [
        ['hacks.mozilla.org', 'article', '6 КБ HTML, 1 запрос к модели'],
        ['svelte.dev', 'article', '12 КБ HTML, 1 запрос'],
        ['carlos-menezes.com', 'article', '12 КБ HTML, 1 запрос'],
        ['daverupert.com', 'article', '14 КБ HTML, 1 запрос'],
        ['infrequently.org', 'article', '18 КБ HTML, 1 запрос'],
        ['ionic.io', '.single-content', '23 КБ HTML, 2 запроса'],
        ['blog.master.dev', 'article', '24 КБ HTML, 2 запроса'],
        ['pnpm.io', '.markdown', 'Docusaurus. 38 КБ HTML, 2 запроса'],
        ['gtkx.dev', '.content-container', 'VitePress. 44 КБ HTML, 3 запроса'],
        ['bhugo.dev', '.prose', '46 КБ HTML, 3 запроса'],
        ['nodejs.org', 'main', 'Классы вёрстки хешируются сборкой. 70 КБ HTML, 4 запроса'],
        ['blog.gaborkoos.com', 'article', '87 КБ HTML, 5 запросов'],
        ['runjs.app', 'article', 'Дорогой: 391 КБ HTML, 20 запросов из 60 суточных'],
        ['tkdodo.eu', 'article', 'Дорогой: 536 КБ HTML, 27 запросов из 60 суточных'],
    ];

    public function up(): void
    {
        $rows = [];

        foreach (self::SELECTORS as [$domain, $selector, $note]) {
            $rows[] = [
                'domain' => $domain,
                'content_selector' => $selector,
                'note' => 'JavaScript Weekly. '.$note,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('site_selectors')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('site_selectors')
            ->whereIn('domain', array_column(self::SELECTORS, 0))
            ->where('note', 'like', 'JavaScript Weekly.%')
            ->delete();
    }
};
