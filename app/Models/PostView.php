<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostView extends Model
{
    // Фиксируем момент просмотра явным полем viewed_at, отдельные
    // created_at/updated_at здесь не нужны.
    public $timestamps = false;

    /**
     * Имя глобального скоупа, отсекающего роботов.
     *
     * Снимать его нужно осознанно и только там, где робот — предмет разговора:
     * PostView::withoutGlobalScope(PostView::HUMANS_ONLY).
     */
    public const HUMANS_ONLY = 'humans_only';

    /**
     * Дата, с которой у просмотра есть источник перехода и признак робота.
     *
     * Раньше не писалось ни то, ни другое, поэтому у старых записей
     * referer_host = NULL означает «неизвестно», а не «прямой заход», и
     * is_bot = false означает «не проверяли», а не «человек». Обе цифры
     * пришлось бы читать наоборот, поэтому потребители явно отсекают историю:
     * виджет источников считает только записи с этой даты, а команда
     * post-views:mark-bots, наоборот, работает только до неё.
     *
     * Граница — день выката, с точностью до суток. Просмотры этого дня до
     * применения миграции реферера не имеют и в виджете попадут в «прямые
     * заходы»: за неполный день их считанные десятки, и через сутки они уйдут
     * из окна сами. Сдвинуть границу на следующий день нельзя — тогда до его
     * наступления виджет не показывал бы ничего, а команда разметки считала бы
     * историей записи, уже размеченные по User-Agent, то есть ровно тот случай,
     * от которого её и ограничивают.
     */
    public const ATTRIBUTION_SINCE = '2026-08-17';

    protected $fillable = [
        'post_id',
        'ip_hash',
        'session_hash',
        'is_bot',
        'referer_host',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'is_bot' => 'boolean',
    ];

    /**
     * По умолчанию просмотром считается только заход человека.
     *
     * Скоуп глобальный, а не локальный, потому что читателей у таблицы четыре и
     * они разнородны: счётчик под статьёй (Post::viewsCount), withCount('views')
     * в популярных постах главной и в топе аналитики, Trend в графике. Локальный
     * скоуп пришлось бы дописывать в каждом — ровно так уже разъезжалось условие
     * «раздел с опубликованными постами», пока его не свели к одному месту.
     *
     * На запись скоуп не влияет: insert через relation сохраняет и роботов.
     * Два места читают таблицу мимо Eloquent (PostViewsOverview) — там условие
     * стоит явно, и это закреплено тестом.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::HUMANS_ONLY, function (Builder $query) {
            $query->where($query->qualifyColumn('is_bot'), false);
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
