<?php

namespace App\Models;

use App\Support\HostMatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Правило разбора одного источника: по какому CSS-селектору искать текст
 * статьи на страницах этого домена. Заводится из админки (см.
 * Filament\Resources\SiteSelectorResource), применяется при парсинге через
 * Support\ContentSelectorResolver.
 */
class SiteSelector extends Model
{
    protected $fillable = [
        'domain',
        'content_selector',
        'note',
    ];

    /**
     * Приводит введённое в админке значение к виду, пригодному для
     * сопоставления с хостом: из вставленного целиком URL берём только хост,
     * убираем 'www.' (правило 'exakat.io' должно матчить и 'www.exakat.io').
     */
    public static function normalizeDomain(?string $value): string
    {
        $value = trim(mb_strtolower((string) $value));

        if (str_contains($value, '://')) {
            $value = parse_url($value, PHP_URL_HOST) ?: $value;
        }

        $value = trim(explode('/', $value)[0]);

        return preg_replace('/^www\./', '', $value);
    }

    /**
     * Селектор для хоста статьи. Домен в правиле матчится по границе метки
     * ('dev.to' подходит для 'www.dev.to', но не для 'dev.to.evil.tld'),
     * поэтому под один URL может подойти несколько правил: берём самое
     * специфичное (самый длинный домен), иначе 'jetbrains.com' перебивал бы
     * 'blog.jetbrains.com'.
     */
    public static function selectorForHost(string $host): ?string
    {
        if ($host === '') {
            return null;
        }

        return self::query()
            ->get(['domain', 'content_selector'])
            ->filter(fn (self $rule) => HostMatcher::matches($host, (string) $rule->domain))
            ->sortByDesc(fn (self $rule) => strlen($rule->domain))
            ->first()
            ?->content_selector;
    }
}
