<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagResource\Pages;
use App\Models\Tag;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $modelLabel = 'Тег';

    protected static ?string $pluralModelLabel = 'Теги';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')->required()->reactive()->afterStateUpdated(function ($set, $state) {
                    $set('code', Str::slug($state));
                }),
                TextInput::make('code')->required()->unique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('title')->label('Название')->sortable(),
                TextColumn::make('code')->label('Код')->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
