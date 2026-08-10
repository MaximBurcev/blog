<?php

namespace App\Models;

use App\Service\HtmlSanitizerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Новость из секции «News and Announcements» дайджеста PHP Weekly.
 *
 * В отличие от Post, полного текста статьи тут нет: заголовок и краткое
 * описание берутся прямо из дайджеста и переводятся, а читатель уходит по
 * ссылке на первоисточник. Поэтому ни content_orig, ни parse_status, ни
 * WebP-вариантов здесь не нужно.
 */
class News extends Model
{
    use HasFactory;

    protected $table = 'news';

    protected $fillable = [
        'url',
        'title',
        'title_orig',
        'summary',
        'summary_orig',
        'source_host',
        'published',
        'translation_incomplete',
    ];

    protected $casts = [
        'published' => 'boolean',
        'translation_incomplete' => 'boolean',
    ];

    /**
     * Описание приходит из чужого письма и выводится на публичной странице.
     * Санитайзим тем же сервисом, что и контент постов: тегов мы тут не ждём
     * вовсе, но полагаться на «источник хороший» нельзя.
     */
    public function setSummaryAttribute(?string $value): void
    {
        $this->attributes['summary'] = filled($value)
            ? app(HtmlSanitizerService::class)->sanitize($value)
            : $value;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }
}
