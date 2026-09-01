<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Filament\Widgets\ParsingStatusOverview;
use App\Models\Post;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /**
     * Очередь ревью одним кликом, без ручной сборки фильтров при каждом
     * входе. Счётчики на бейджах отвечают на вопрос «сколько постов ждут
     * моего ревью» ещё до открытия вкладки.
     */
    public function getTabs(): array
    {
        return [
            // Готовы к вычитке и публикации: спарсены без ошибки, перевод не
            // помечен неполным. Посты на ревью перевода сюда не попадают —
            // сначала разбираемся с переводом, потом вычитываем.
            'review' => Tab::make('На ревью')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('published', false)
                    ->where('parse_status', Post::PARSE_STATUS_OK)
                    ->where('translation_incomplete', false))
                ->badge(fn (): int => Post::query()
                    ->where('published', false)
                    ->where('parse_status', Post::PARSE_STATUS_OK)
                    ->where('translation_incomplete', false)
                    ->count()),
            'translation' => Tab::make('Ревью перевода')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('translation_incomplete', true))
                ->badge(fn (): int => Post::query()->where('translation_incomplete', true)->count())
                ->badgeColor('warning'),
            'failed' => Tab::make('Ошибки парсинга')
                ->modifyQueryUsing(function (Builder $query): Builder {
                    /** @var Builder<Post> $query */
                    return $query->parseFailed();
                })
                ->badge(fn (): int => Post::query()->parseFailed()->count())
                ->badgeColor('danger'),
            'published' => Tab::make('Опубликованные')
                ->modifyQueryUsing(function (Builder $query): Builder {
                    /** @var Builder<Post> $query */
                    return $query->published();
                })
                ->badge(fn (): int => Post::query()->published()->count())
                ->badgeColor('success'),
            'all' => Tab::make('Все'),
        ];
    }

    /**
     * По умолчанию — «Все», а не первая вкладка: при активной «На ревью»
     * поиск по заголовку искал бы только среди готовых черновиков и молча
     * не находил опубликованные посты.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Состояние парсинга нужно там же, где смотрят на результат парсинга —
     * над списком постов, а не только на инфопанели.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ParsingStatusOverview::class,
        ];
    }
}
