<?php

namespace App\Service;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Санитайзинг HTML контента постов перед сохранением. Контент — это чужой
 * скрейпленный HTML (см. StorePostJob), рендерится через {!! $post->content !!}
 * на публичной странице и в Summernote в админке без экранирования, поэтому
 * <script>/onerror/javascript: из страницы-источника исполнялись бы у
 * посетителей и у админа при редактировании (stored XSS).
 */
class HtmlSanitizerService
{
    // h1 намеренно НЕ разрешён: страница поста уже выводит собственный <h1>
    // с заголовком, а заголовок первого уровня внутри скрейпленного текста
    // давал второй h1 на странице (было у 50 постов). Такие узлы HTMLPurifier
    // выкидывает вместе с тегом, оставляя текст.
    private const ALLOWED_HTML = 'p,br,hr,h2,h3,h4,h5,h6,strong,b,em,i,u,s,'.
        'a[href|title],img[src|alt|width|height],'.
        'ul,ol,li,blockquote,pre,code,'.
        'table,thead,tbody,tr,td,th,'.
        'span,div';

    /**
     * HTMLPurifier переиспользуется между вызовами.
     *
     * Раньше и конфиг, и сам пурифаер собирались заново на каждый вызов, причём
     * с Cache.DefinitionImpl = null, то есть с выключенным кэшем определений:
     * весь набор разрешённых тегов и атрибутов разбирался с нуля на каждую
     * статью. При санитайзинге поста мутаторы модели дёргают сервис дважды
     * (content и content_orig), а команда пересанитайзинга — на каждый из
     * сотен постов подряд.
     */
    private ?HTMLPurifier $purifier = null;

    /**
     * Счётчики, которые источники вставляют в тело статьи под видом картинки.
     *
     * Medium кладёт `<img src="https://medium.com/_/stat?event=post.clientViewed...">`
     * прямо в content:encoded своего RSS — и после публикации у нас этот
     * пиксель сообщал бы Medium о КАЖДОМ просмотре страницы на нашем сайте:
     * IP читателя, User-Agent, реферер. HTMLPurifier такое не ловит: с его
     * точки зрения это обычный разрешённый <img> с http-адресом.
     *
     * Остальные шаблоны — не про Medium: это счётчики, которые попадаются
     * в скрейпленных статьях с других площадок.
     */
    private const TRACKING_PIXEL_PATTERNS = [
        '#medium\.com/_/stat#i',
        '#google-analytics\.com#i',
        '#stats\.wordpress\.com#i',
        '#pixel\.wp\.com#i',
        '#scorecardresearch\.com#i',
        '#doubleclick\.net#i',
        '#feedburner\.com#i',
    ];

    /**
     * @param  bool  $stripRemoteImages  Вырезать картинки, которые грузятся с
     *                                   чужих доменов. Нужно там, где
     *                                   показывается текст, не прошедший через
     *                                   ContentImageService — то есть
     *                                   content_orig: в нём остались адреса
     *                                   первоисточника, и браузер читателя
     *                                   ходил бы за каждой картинкой на
     *                                   medium/dev.to, отдавая им свой IP.
     */
    public function sanitize(string $html, bool $stripRemoteImages = false): string
    {
        $html = $this->flattenCodeBlocks($html);
        $html = $this->stripTrackingPixels($this->demoteTopHeadings($html));

        if ($stripRemoteImages) {
            $html = $this->stripRemoteImages($html);
        }

        return $this->purifier()->purify($html);
    }

    /**
     * Вырезает <img>-счётчики целиком (вместе с тегом), а не только их src:
     * пустой <img> в тексте статьи — такой же мусор, просто безвредный.
     */
    private function stripTrackingPixels(string $html): string
    {
        return $this->stripImagesWhere($html, function (string $url): bool {
            foreach (self::TRACKING_PIXEL_PATTERNS as $pattern) {
                if (preg_match($pattern, $url) === 1) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Вырезает картинки с чужих доменов.
     *
     * Свои (относительные и с нашего хоста) остаются: в content они как раз
     * локальные — их скачал ContentImageService. Те же иллюстрации читатель
     * видит в переводе, поэтому их отсутствие в оригинале ничего не теряет,
     * зато страница перестаёт быть хотлинком на чужой CDN — а он на запросы
     * со стороны отвечает и 403, то есть битой картинкой.
     */
    private function stripRemoteImages(string $html): string
    {
        $ownHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $this->stripImagesWhere($html, function (string $url) use ($ownHost): bool {
            $host = parse_url($url, PHP_URL_HOST);

            return $host !== null && $host !== '' && $host !== $ownHost;
        });
    }

    /**
     * Общий разбор <img> для обоих фильтров.
     *
     * Паттерн учитывает кавычки: наивный <img[^>]*> обрывался на первом '>',
     * и `<img alt=">" src="https://medium.com/_/stat...">` проходил мимо
     * фильтра целиком. Проверяется только значение src — иначе валидная
     * картинка с упоминанием счётчика в alt удалялась бы.
     *
     * @param  callable(string): bool  $shouldRemove
     */
    private function stripImagesWhere(string $html, callable $shouldRemove): string
    {
        $cleaned = preg_replace_callback(
            '#<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
            function (array $matches) use ($shouldRemove): string {
                if (preg_match('/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $matches[0], $src) !== 1) {
                    return $matches[0];
                }

                $url = $src[2] ?? '';
                if ($url === '') {
                    $url = $src[3] ?? $src[4] ?? '';
                }

                return $shouldRemove($url) ? '' : $matches[0];
            },
            $html
        );

        return $cleaned ?? $html;
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // Сериализованные определения кладём в storage, а не в vendor: каталог
        // vendor на проде только для чтения, и запись туда молча падала бы.
        $config->set('Cache.SerializerPath', $this->definitionCachePath());

        return $this->purifier = new HTMLPurifier($config);
    }

    private function definitionCachePath(): string
    {
        $path = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * Блочные теги, которые подсветчики кладут внутрь <pre><code>.
     *
     * torchlight рендерит каждую строку кода отдельным <div class="line">. По
     * модели контента HTML это недопустимо: <code> строчный, <div> блочный.
     * Shiki и Prism здесь ни при чём — они используют <span class="line">,
     * который модель не нарушает.
     *
     * Таблиц в списке нет намеренно. Табличная вёрстка с номерами строк живёт
     * снаружи (<table>…<td><pre>), а <table> ВНУТРИ <pre> до нас не доезжает:
     * libxml растаскивает её ещё при разборе, ровно как это сделал бы браузер,
     * и к моменту обхода <pre> уже пуст. Постов с такой разметкой мы не
     * встречали ни одного; появятся — чинить придётся до парсера.
     */
    private const BLOCKS_INSIDE_PRE = ['div', 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article'];

    /**
     * Схлопывает разметку внутри <pre> в текст со строками.
     *
     * Без этого HTMLPurifier честно приводит документ к модели контента:
     * выносит <div> НАРУЖУ из <code>, оставляя пустой <pre><code></code></pre>,
     * а сам код — россыпью соседних <div><code>…</code></div> после него. На
     * странице это выглядит как «код вне блока подсветки»: тёмная полоса
     * пустого <pre>, а под ней строки кода обычным текстом через интервал.
     * На 25.08.2026 так побились 15 постов из 211.
     *
     * Схлопываем в текст, а не пытаемся сохранить разметку строк: подсветку на
     * странице делает наш фронтенд, а <div class="line"> — презентация чужого
     * подсветчика. Класс языка не переносим: атрибуты у pre и code не
     * разрешены (ALLOWED_HTML), и HTMLPurifier срезал бы его следующим шагом.
     *
     * Разбираем DOM, а не режем регуляркой, и это не вкусовщина. Первая версия
     * искала блоки паттерном `<pre\b[^>]*>(.*?)</pre>` и вынимала текст через
     * strip_tags — ревью показало две потери данных: `<pre` внутри атрибута
     * (`alt="<pre>"`) открывал ложный блок и съедал всё до следующего
     * настоящего `</pre>`, а strip_tags на строке `if (a <b) return;` молча
     * отрезает остаток листинга, приняв `<b) return;` за тег.
     */
    private function flattenCodeBlocks(string $html): string
    {
        if (stripos($html, '<pre') === false) {
            return $html;
        }

        $dom = new \DOMDocument;

        if (! @$dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        )) {
            return $html;
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body instanceof \DOMElement) {
            return $html;
        }

        $finder = new \DOMXPath($dom);
        $blocks = './/'.implode('|.//', self::BLOCKS_INSIDE_PRE);
        $changed = false;

        foreach (iterator_to_array($finder->query('//pre')) as $pre) {
            if (! $pre instanceof \DOMElement || $finder->query($blocks, $pre)->length === 0) {
                continue;
            }

            $text = trim($this->codeText($pre), "\n");

            while ($pre->firstChild) {
                $pre->removeChild($pre->firstChild);
            }

            $code = $dom->createElement('code');
            // Текстовым узлом: DOM сам экранирует < и & при сериализации, и
            // никакая строка кода не может превратиться в разметку.
            $code->appendChild($dom->createTextNode($text));
            $pre->appendChild($code);

            $changed = true;
        }

        if (! $changed) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Текст блока кода: блочные узлы дают перенос строки.
     *
     * script и style пропускаем целиком: до этой правки их содержимое удалял
     * HTMLPurifier вместе с тегом, а вынь мы его в текст — чужой скрипт стал
     * бы виден читателю как часть листинга.
     */
    private function codeText(\DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->nodeValue;

                continue;
            }

            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, ['script', 'style'], true)) {
                continue;
            }

            if ($tag === 'br') {
                $text .= "\n";

                continue;
            }

            $inner = $this->codeText($child);

            $text .= in_array($tag, self::BLOCKS_INSIDE_PRE, true)
                ? rtrim($inner)."\n"
                : $inner;
        }

        return $text;
    }

    /**
     * Понижает h1 из тела статьи до h2. Просто выкинуть тег (он не в
     * ALLOWED_HTML) нельзя — HTMLPurifier оставил бы текст заголовка голым
     * абзацем, потеряв структуру документа. А оставить как есть — второй
     * <h1> на странице поста вдобавок к заголовку самой страницы.
     */
    private function demoteTopHeadings(string $html): string
    {
        return preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2>', $html) ?? $html;
    }
}
