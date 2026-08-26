<?php

namespace App\Support;

use App\Models\SiteSelector;

/**
 * Единая точка выбора CSS-селектора блока с текстом статьи по её URL.
 * Раньше эта логика была продублирована в ReleaseService и ParsePostCommand,
 * и оба читали только config/releases.php — из-за чего новый источник
 * нельзя было добавить без правки кода.
 *
 * Порядок поиска: правило из админки (таблица site_selectors), затем
 * конфиг (фолбэк для доменов, которые ещё не перенесены), затем глобальный
 * 'post_selector'.
 */
class ContentSelectorResolver
{
    public function resolve(string $url): string
    {
        return $this->resolveWithSource($url)['selector'];
    }

    /**
     * То же, что resolve(), но с указанием, откуда взялся селектор — это
     * нужно форме парсинга, чтобы предупредить: для домена правила нет,
     * применится общий фолбэк и контент, скорее всего, не найдётся.
     *
     * @return array{selector: string, source: 'admin'|'config'|'fallback'}
     */
    public function resolveWithSource(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        $fromAdmin = SiteSelector::selectorForHost($host);
        if ($fromAdmin !== null) {
            return ['selector' => $fromAdmin, 'source' => 'admin'];
        }

        $fromConfig = $this->fromConfig($host);
        if ($fromConfig !== null) {
            return ['selector' => $fromConfig, 'source' => 'config'];
        }

        return [
            'selector' => config('releases.post_selector', 'article-body'),
            'source' => 'fallback',
        ];
    }

    /**
     * Самое специфичное правило, а не первое подходящее — тем же порядком
     * идёт SiteSelector::selectorForHost(). Разница видна с ростом карты:
     * рядом с 'hacks.mozilla.org' достаточно однажды завести 'mozilla.org',
     * чтобы статьи поддомена начали разбираться чужим селектором и молча
     * превращаться в заглушки.
     */
    private function fromConfig(string $host): ?string
    {
        return HostMatcher::lookup($host, config('releases.domain_selectors', []));
    }
}
