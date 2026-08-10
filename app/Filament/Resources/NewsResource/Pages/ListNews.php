<?php

namespace App\Filament\Resources\NewsResource\Pages;

use App\Filament\Resources\NewsResource;
use App\Models\Release;
use App\Service\NewsImportService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListNews extends ListRecords
{
    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Импорт синхронный: секция дайджеста — это 8-10 коротких текстов,
            // и ждать очередь ради них дольше, чем выполнить. Ошибка источника
            // тоже видна сразу, а не в failed_jobs.
            Actions\Action::make('import')
                ->label('Импортировать из дайджестов')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalDescription('Пройдёт по всем заведённым релизам и добавит новости, которых ещё нет.')
                ->action(function (NewsImportService $service) {
                    $urls = Release::query()->pluck('url');

                    if ($urls->isEmpty()) {
                        Notification::make()->warning()
                            ->title('Нет ни одного релиза')
                            ->body('Заведите дайджест в разделе «Релизы».')
                            ->send();

                        return;
                    }

                    $imported = 0;
                    $failed = [];

                    foreach ($urls as $url) {
                        try {
                            $imported += $service->importFromDigest($url)['imported'];
                        } catch (\Throwable $e) {
                            $failed[] = parse_url($url, PHP_URL_HOST).': '.mb_substr($e->getMessage(), 0, 80);
                        }
                    }

                    if ($failed !== []) {
                        Notification::make()->warning()
                            ->title("Добавлено новостей: {$imported}, но часть дайджестов не разобрана")
                            ->body(implode('; ', $failed))
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title("Добавлено новостей: {$imported}")
                        ->body($imported === 0 ? 'Все новости из дайджестов уже импортированы.' : null)
                        ->send();
                }),
        ];
    }
}
