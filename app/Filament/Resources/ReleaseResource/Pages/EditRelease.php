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
                ->modalDescription('Со страницы будут заново собраны ссылки на статьи, и для каждой в фоне запустится парсинг поста. Уже существующие посты обновятся, дублей не будет.')
                ->modalSubmitActionLabel('Отправить в очередь')
                ->action(fn () => ReleaseResource::dispatchParse($this->record)),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
