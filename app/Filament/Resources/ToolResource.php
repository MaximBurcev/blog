<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ToolResource\Pages;
use App\Models\Tool;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ToolResource extends Resource
{
    protected static ?string $model = Tool::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $modelLabel = 'Инструмент';

    protected static ?string $pluralModelLabel = 'Инструменты';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('vendor/package')
                    ->helperText('Как названо в дайджесте — обычно vendor/package.'),
                TextInput::make('url')
                    ->label('Ссылка')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Такой инструмент уже есть в разделе.'])
                    ->placeholder('https://github.com/vendor/package'),
                Textarea::make('description')
                    ->label('Описание на русском')
                    ->rows(3)
                    ->helperText('Пустое — на сайте покажется английский оригинал.'),
                Textarea::make('description_orig')
                    ->label('Описание из дайджеста')
                    ->rows(3),
                Toggle::make('is_published')
                    ->label('Показывать на сайте')
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Tool $record): string => $record->url)
                    ->openUrlInNewTab(),
                TextColumn::make('description')
                    ->label('Описание')
                    ->wrap()
                    ->limit(120)
                    ->state(fn (Tool $record): string => $record->displayDescription())
                    ->description(fn (Tool $record): ?string => $record->description ? null : 'без перевода')
                    ->searchable(['description', 'description_orig']),
                IconColumn::make('is_published')->label('На сайте')->boolean()->sortable(),
                TextColumn::make('translated_by')->label('Переводчик')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Добавлен')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('Показывается на сайте'),
                Tables\Filters\Filter::make('untranslated')
                    ->label('Без перевода')
                    ->query(fn ($query) => $query->whereNull('description')),
                Tables\Filters\TrashedFilter::make()->label('Удалённые'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTools::route('/'),
            'create' => Pages\CreateTool::route('/create'),
            'edit' => Pages\EditTool::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
