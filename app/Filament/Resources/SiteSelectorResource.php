<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSelectorResource\Pages;
use App\Models\SiteSelector;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Правила разбора источников: домен → CSS-селектор блока с текстом статьи.
 * Раньше эти правила жили только в config/releases.php и новый источник
 * нельзя было добавить без правки кода и деплоя.
 */
class SiteSelectorResource extends Resource
{
    protected static ?string $model = SiteSelector::class;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $recordTitleAttribute = 'domain';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $modelLabel = 'Селектор сайта';

    protected static ?string $pluralModelLabel = 'Селекторы сайтов';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('domain')
                    ->label('Домен')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Правило для этого домена уже есть — отредактируйте существующее.'])
                    ->placeholder('example.com')
                    ->helperText('Достаточно части хоста: правило example.com сработает и для www.example.com. Можно вставить целиком ссылку на статью — домен вытащится сам.')
                    ->dehydrateStateUsing(fn (?string $state): string => SiteSelector::normalizeDomain($state)),
                TextInput::make('content_selector')
                    ->label('Селектор контента')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('.post-content')
                    ->helperText('Блок с текстом статьи: .класс, #id или имя тега (article). Имя класса можно писать и без точки.'),
                TextInput::make('note')
                    ->label('Примечание')
                    ->maxLength(255)
                    ->helperText('Необязательно: чем этот блок отличается от соседних, что пришлось исключить.'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('domain')->label('Домен')->searchable()->sortable(),
                TextColumn::make('content_selector')->label('Селектор контента')->searchable(),
                TextColumn::make('note')->label('Примечание')->wrap()->toggleable(),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('domain')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteSelectors::route('/'),
            'create' => Pages\CreateSiteSelector::route('/create'),
            'edit' => Pages\EditSiteSelector::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
