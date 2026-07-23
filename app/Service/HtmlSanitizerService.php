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
    private const ALLOWED_HTML = 'p,br,hr,h1,h2,h3,h4,h5,h6,strong,b,em,i,u,s,'.
        'a[href|title],img[src|alt|width|height],'.
        'ul,ol,li,blockquote,pre,code,'.
        'table,thead,tbody,tr,td,th,'.
        'span,div';

    public function sanitize(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.DefinitionImpl', null);

        return (new HTMLPurifier($config))->purify($html);
    }
}
