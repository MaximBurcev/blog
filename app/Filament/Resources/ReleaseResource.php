<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReleaseResource\Pages;
use App\Jobs\ImportToolsJob;
use App\Jobs\ParseReleaseJob;
use App\Models\Release;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Релиз — страница чужого дайджеста (рассылка mailer.inovica.com, JS Weekly
 * и т.п.), с которой ReleaseService собирает ссылки на статьи и запускает
 * StorePostJob для каждой. Раньше релизы жили только в отключённом разделе
 * /admin и в команде release:parse.
 */
class ReleaseResource extends Resource
{
    protected static ?string $model = Release::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $recordTitleAttribute = 'url';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $modelLabel = 'Релиз';

    protected static ?string $pluralModelLabel = 'Релизы';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('url')
                    ->label('Ссылка на страницу релиза')
                    ->required()
                    ->url()
                    ->maxLength(2048)
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Такой релиз уже добавлен — его можно спарсить заново из списка.'])
                    ->placeholder('http://mailer.inovica.com/newsletter.php?id=1165&eid=13609351')
                    ->helperText('Страница дайджеста со списком ссылок на статьи. После создания релиза ссылки разбираются автоматически, для каждой статьи создаётся пост.')
                    // rtrim('/') — тем же способом ReleaseService приводит url
                    // к каноничному виду, иначе тот же дайджест со слешем на
                    // конце прошёл бы проверку уникальности как новый релиз.
                    ->dehydrateStateUsing(fn (?string $state): string => rtrim(trim((string) $state), '/')),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('url')
                    ->label('Ссылка')
                    ->searchable()
                    ->wrap()
                    ->url(fn (Release $record): string => $record->url)
                    ->openUrlInNewTab(),
                TextColumn::make('created_at')->label('Добавлен')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                self::parseAction(),
                self::importToolsAction(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Повторный разбор дайджеста. Идемпотентен: посты дедуплицируются по url
     * (PostService::store), поэтому уже созданные обновятся, а не задублируются
     * — это же и способ дотянуть статьи, которые в первый раз упали из-за
     * отсутствующего селектора.
     */
    public static function parseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('parse')
            ->label('Спарсить')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Спарсить релиз')
            ->modalDescription(fn (): string => sprintf(
                'Со страницы будут собраны ссылки на статьи (не более %d, пропуская первые %d — см. настройки releases.max_links/offset), и для каждой в фоне запустится парсинг поста. Уже существующие посты обновятся, дублей не будет. Заодно из секции «%s» импортируются инструменты — отдельно нажимать не нужно.',
                (int) config('releases.max_links'),
                (int) config('releases.offset'),
                (string) config('releases.tools_section_heading'),
            ))
            ->modalSubmitActionLabel('Отправить в очередь')
            ->action(fn (Release $record) => self::dispatchParse($record));
    }

    public static function importToolsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('importTools')
            ->label('Импортировать инструменты')
            ->icon('heroicon-o-cube')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Импортировать инструменты из дайджеста')
            ->modalDescription(fn (): string => sprintf(
                'Из секции «%s» будут добавлены утилиты и библиотеки — только имя, ссылка и описание, без разбора страниц. Уже добавленные пропускаются. Описания переводятся одним запросом к модели на весь выпуск; если квота исчерпана, они останутся английскими.',
                (string) config('releases.tools_section_heading'),
            ))
            ->modalSubmitActionLabel('Отправить в очередь')
            ->action(fn (Release $record) => self::dispatchToolsImport($record));
    }

    public static function dispatchToolsImport(Release $release): void
    {
        ImportToolsJob::dispatch($release->url);

        Notification::make()
            ->title('Импорт инструментов отправлен в очередь')
            ->body('Инструменты появятся в разделе «Инструменты» по мере обработки очереди.')
            ->success()
            ->send();
    }

    public static function dispatchParse(Release $release): void
    {
        ParseReleaseJob::dispatch($release->url);

        Notification::make()
            ->title('Релиз отправлен на парсинг')
            ->body('Ссылки разбираются в фоне, посты появятся в разделе «Посты» по мере обработки очереди. Статьи, которые не удалось разобрать, тоже станут постами — с причиной сбоя.')
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReleases::route('/'),
            'create' => Pages\CreateRelease::route('/create'),
            'edit' => Pages\EditRelease::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
