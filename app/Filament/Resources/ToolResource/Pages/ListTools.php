<?php

namespace App\Filament\Resources\ToolResource\Pages;

use App\Filament\Resources\ToolResource;
use App\Jobs\TranslateToolsJob;
use App\Models\Tool;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTools extends ListRecords
{
    protected static string $resource = ToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('translatePending')
                ->label('Перевести непереведённые')
                ->icon('heroicon-o-language')
                ->color('gray')
                ->visible(fn (): bool => self::pendingCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Перевести описания')
                ->modalDescription(fn (): string => sprintf(
                    'Английскими остались %d описаний — обычно потому, что на момент импорта была исчерпана квота модели. Перевод пойдёт в фоне: сначала одним запросом на всю пачку, затем поштучно тем, кому пачка не помогла.',
                    self::pendingCount(),
                ))
                ->modalSubmitActionLabel('Отправить в очередь')
                ->action(function (): void {
                    TranslateToolsJob::dispatch();

                    Notification::make()
                        ->title('Перевод отправлен в очередь')
                        ->body('Описания обновятся по мере обработки очереди.')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    private static function pendingCount(): int
    {
        return Tool::query()->whereNull('description')->whereNotNull('description_orig')->count();
    }
}
