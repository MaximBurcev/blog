<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Filament\Widgets\ParsingStatusOverview;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

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
