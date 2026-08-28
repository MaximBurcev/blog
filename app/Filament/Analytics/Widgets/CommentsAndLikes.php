<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\Comment;
use App\Models\PostLike;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Комментарии и лайки — вовлечённость, которую не показывает ни один виджет
 * просмотров: таблицы comments и post_likes до сих пор никто не читал.
 *
 * Окна здесь фиксированные (7 дней), а не из фильтра периода страницы:
 * модерация — это очередь, её состояние не должно зависеть от того, какой
 * период трафика сейчас выбран.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class CommentsAndLikes extends BaseWidget
{
    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Комментарии и лайки';

    protected function getStats(): array
    {
        $weekAgo = now()->subWeek();

        $comments = Comment::count();
        // «На модерации» — неопубликованные: флаг published снимается кликом
        // в админке и независим от soft delete (см. миграцию
        // add_published_to_comments_table).
        $pending = Comment::where('published', false)->count();
        $commentsWeek = Comment::where('created_at', '>=', $weekAgo)->count();

        $likes = PostLike::count();
        $likesWeek = PostLike::where('created_at', '>=', $weekAgo)->count();

        return [
            Stat::make('Комментариев всего', (string) $comments)
                ->description('На модерации: '.$pending)
                ->icon('heroicon-m-chat-bubble-left-right')
                // Непустая очередь модерации — повод заглянуть в раздел, а не
                // фоновая цифра.
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('Комментариев за 7 дней', (string) $commentsWeek)
                ->description('Включая ждущие модерации')
                ->icon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Лайков всего', (string) $likes)
                ->description('За 7 дней: '.$likesWeek)
                ->icon('heroicon-m-heart')
                ->color('gray'),
        ];
    }
}
