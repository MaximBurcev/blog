<?php

namespace App\Filament\Analytics\Widgets;

use App\Filament\Resources\PostResource;
use App\Models\Post;
use App\Support\AnalyticsPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Что именно читают: посты, отсортированные по просмотрам за выбранный период.
 *
 * Рядом с периодом показан накопленный итог — без него нельзя отличить
 * свежую статью, которую читают прямо сейчас, от старой, которая набрала свои
 * просмотры год назад и попала в топ хвостом трафика.
 */
class TopPostsByViews extends BaseWidget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = AnalyticsPeriod::startsAt($this->filters);
        // Одно определение границы на оба использования: счётчик и отсечку.
        // Разъехавшись, они дали бы строки с нулём в колонке «За период» —
        // отбор пропустил бы пост по одному условию, а счётчик посчитал по
        // другому.
        $withinPeriod = fn (Builder $query): Builder => $query->where('viewed_at', '>=', $since);

        return $table
            ->heading('Самые читаемые посты')
            ->description('За '.AnalyticsPeriod::label($this->filters).'. Посты без просмотров за период не показаны.')
            ->query(
                Post::query()
                    ->withCount([
                        'views as period_views_count' => $withinPeriod,
                        'views as total_views_count',
                    ])
                    // Отсечка сделана EXISTS, а не having по алиасу счётчика:
                    // having ломает подсчёт строк для пагинации (Laravel
                    // считает его отдельным агрегатом по тому же запросу), а
                    // без отсечки в список попадают все посты блога — те, у
                    // кого за период ноль, сортировка уводит вниз, но
                    // пагинация всё равно листает их до последней страницы.
                    ->whereHas('views', $withinPeriod)
            )
            ->defaultSort('period_views_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Пост')
                    ->wrap()
                    ->description(fn (Post $record): string => $record->category?->title ?? 'Без категории')
                    ->url(fn (Post $record): string => PostResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('period_views_count')
                    ->label('За период')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_views_count')
                    ->label('Всего')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_news')
                    ->label('Раздел')
                    ->icon(fn (?bool $state): string => $state ? 'heroicon-o-megaphone' : 'heroicon-o-document-text')
                    ->color('gray')
                    ->tooltip(fn (Post $record): string => $record->is_news ? 'Новость' : 'Статья'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('На сайте')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    // permalink(), а не route('post.show'): у новостей свой
                    // адрес, и ссылка на /posts/{code} была бы редиректом.
                    ->url(fn (Post $record): string => $record->permalink())
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Просмотров за период нет')
            ->emptyStateDescription('Либо период слишком короткий, либо счётчик просмотров ещё не набрал данных.')
            ->emptyStateIcon('heroicon-o-eye-slash')
            ->paginated([10, 25, 50]);
    }
}
