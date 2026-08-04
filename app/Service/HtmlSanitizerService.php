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

    public function sanitize(string $html): string
    {
        return $this->purifier()->purify($this->demoteTopHeadings($html));
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
