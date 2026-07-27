<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommentResource\Pages;
use App\Models\Comment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $recordTitleAttribute = 'message';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $modelLabel = 'Комментарий';

    protected static ?string $pluralModelLabel = 'Комментарии';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('post_id')
                    ->relationship('post', 'title')
                    ->searchable()
                    ->required()
                    ->label('Пост'),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->nullable()
                    ->label('Пользователь')
                    ->helperText('Оставьте пустым для гостевого комментария'),
                Forms\Components\TextInput::make('guest_name')
                    ->nullable()
                    ->label('Имя гостя')
                    ->helperText('Заполняется только если комментарий не привязан к пользователю'),
                Forms\Components\Textarea::make('message')
                    ->required()
                    ->rows(4)
                    ->label('Текст комментария'),
                Forms\Components\Toggle::make('published')
                    ->label('Опубликован')
                    ->helperText('Выключите, чтобы скрыть комментарий с публичной страницы поста, не удаляя его'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('post.title')
                    ->label('Пост')
                    ->limit(40)
                    ->sortable()
                    ->searchable()
                    ->url(fn (Comment $record): ?string => $record->post
                        ? PostResource::getUrl('edit', ['record' => $record->post])
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('author')
                    ->label('Автор')
                    ->getStateUsing(fn (Comment $record): string => $record->authorName()),
                TextColumn::make('message')
                    ->label('Комментарий')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\ToggleColumn::make('published')
                    ->label('Опубликован'),
                TextColumn::make('view_on_site')
                    ->label('На сайте')
                    ->getStateUsing(fn (): string => 'Открыть →')
                    ->color('primary')
                    ->url(fn (Comment $record): ?string => $record->post
                        ? route('post.show', $record->post->code).'#comments'
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\Filter::make('guests_only')
                    ->label('Только гостевые')
                    ->query(fn ($query) => $query->whereNull('user_id')),
                Tables\Filters\TernaryFilter::make('published')
                    ->label('Опубликован'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComments::route('/'),
            'edit' => Pages\EditComment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
