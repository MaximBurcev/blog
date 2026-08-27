<?php

namespace App\Filament\Resources\ReleaseResource\Pages;

use App\Filament\Resources\ReleaseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRelease extends EditRecord
{
    protected static string $resource = ReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('parse')
                ->label('Спарсить')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Спарсить релиз')
                ->modalDescription('Со страницы будут заново собраны ссылки на статьи, и для каждой в фоне запустится парсинг поста. Уже существующие посты обновятся, дублей не будет. Заодно импортируются инструменты из секции с утилитами и библиотеками — отдельно нажимать не нужно.')
                ->modalSubmitActionLabel('Отправить в очередь')
                ->action(fn () => ReleaseResource::dispatchParse($this->record)),
            Actions\Action::make('importTools')
                ->label('Импортировать инструменты')
                ->icon('heroicon-o-cube')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Импортировать инструменты из дайджеста')
                ->modalDescription('Из секции с утилитами и библиотеками будут добавлены инструменты — имя, ссылка и описание, без разбора страниц. Уже добавленные пропускаются.')
                ->modalSubmitActionLabel('Отправить в очередь')
                ->action(fn () => ReleaseResource::dispatchToolsImport($this->record)),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
