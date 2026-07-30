<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reparse')
                ->label('Перепарсить')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->url))
                ->requiresConfirmation()
                ->modalHeading('Перепарсить статью')
                ->modalDescription('Страница будет заново скачана и разобрана в фоне. Если разбор снова не удастся, уже сохранённый контент не потеряется — обновится только причина сбоя.')
                ->modalSubmitActionLabel('Отправить в очередь')
                ->action(fn () => PostResource::dispatchReparse($this->record)),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
