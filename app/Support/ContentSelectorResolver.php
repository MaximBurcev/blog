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

    private function fromConfig(string $host): ?string
    {
        foreach (config('releases.domain_selectors', []) as $domain => $selector) {
            if ($host !== '' && str_contains($host, $domain)) {
                return $selector;
            }
        }

        return null;
    }
}
