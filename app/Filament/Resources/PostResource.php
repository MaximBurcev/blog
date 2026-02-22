<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Filament\Resources\PostResource\RelationManagers;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Components\BelongsToSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute  = 'title';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $modelLabel = 'Пост';

    protected static ?string $pluralModelLabel = 'Посты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')->required()->reactive()->afterStateUpdated(function ($set, $state) {
                    $set('code', Str::slug($state));
                })->label('Заголовок'),
                TextInput::make('code')->required()->label('Код (slug)'),
                Forms\Components\RichEditor::make('content')->required()->label('Контент'),
                Forms\Components\Select::make('category_id')->relationship('category', 'title')->required()->options(Category::all()->pluck('title', 'id'))
                    ->searchable()->label('Категория'),
                Forms\Components\FileUpload::make('preview_image')->nullable()->label('Превью-изображение'),
                Forms\Components\FileUpload::make('main_image')->nullable()->label('Главное изображение'),
                Forms\Components\Checkbox::make('published')->label('Опубликован'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\ImageColumn::make('preview_image')->label('Превью'),
                TextColumn::make('title')->label('Заголовок')->sortable(),
                //TextColumn::make('category.title')->label('Category')->sortable()->url(fn(Post $record) => CategoryResource::getUrl('edit', ['record' => $record->category])),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\Filter::make('published')->label('Опубликован'),
                Tables\Filters\Filter::make('created_at')->label('Дата создания')->form([
                    Forms\Components\DatePicker::make('created_from')->label('С'),
                    Forms\Components\DatePicker::make('created_until')->label('По'),
                ])
                ->query(function (Builder $query, array $data) {
                    return $query->when($data['created_from'], fn($query) => $query->whereDate('created_at', '>=', $data['created_from']))
                        ->when($data['created_until'], fn($query) => $query->whereDate('created_at', '<=', $data['created_until']));
                }),
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
            RelationManagers\TagsRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit'   => Pages\EditPost::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
