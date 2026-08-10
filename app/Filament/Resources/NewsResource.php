<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsResource\Pages;
use App\Models\News;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Новость';

    protected static ?string $pluralModelLabel = 'Новости';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required()->label('Заголовок'),
            Textarea::make('summary')->required()->rows(5)->label('Описание'),
            TextInput::make('url')->required()->url()->label('Ссылка на первоисточник')
                ->helperText('Ключ дедупликации: повторный импорт того же дайджеста новость не задвоит.'),
            Toggle::make('published')->label('Опубликована'),

            // Оригиналы только на чтение: они нужны, чтобы сверить перевод,
            // а править их бессмысленно — источник от этого не изменится.
            TextInput::make('title_orig')->disabled()->label('Заголовок в оригинале'),
            Textarea::make('summary_orig')->disabled()->rows(4)->label('Описание в оригинале'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Заголовок')->wrap()->searchable()
                    ->description(fn (News $record): ?string => $record->source_host),
                TextColumn::make('summary')->label('Описание')->wrap()
                    ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 160))
                    ->toggleable(),
                IconColumn::make('published')->boolean()->label('Опубл.')->sortable(),
                IconColumn::make('translation_incomplete')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success')
                    ->label('Перевод')
                    ->tooltip(fn (News $record): ?string => $record->translation_incomplete
                        ? 'Перевод не удался — показан оригинал, требует ревью'
                        : null)
                    ->sortable(),
                TextColumn::make('created_at')->dateTime('d.m.Y H:i')->label('Добавлена')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('published')->label('Опубликована'),
                Tables\Filters\TernaryFilter::make('translation_incomplete')->label('Проблема с переводом'),
            ])
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
            'index' => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit' => Pages\EditNews::route('/{record}/edit'),
        ];
    }
}
