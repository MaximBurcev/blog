<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Filament\Resources\PostResource\RelationManagers;
use App\Jobs\StorePostJob;
use App\Models\Category;
use App\Models\Post;
use App\Support\ContentSelectorResolver;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'title';

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
                Forms\Components\FileUpload::make('preview_image')->nullable()->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                    ->maxSize(5120)->label('Превью-изображение'),
                Forms\Components\FileUpload::make('main_image')->nullable()->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                    ->maxSize(5120)->label('Главное изображение'),
                Forms\Components\Checkbox::make('published')->label('Опубликован'),
                // Пост, который не удалось разобрать парсером, всё равно
                // заводится — здесь видно, почему он пустой.
                Forms\Components\Placeholder::make('parse_error_note')
                    ->label('Ошибка парсинга')
                    ->content(fn (?Post $record): string => (string) $record?->parse_error)
                    ->visible(fn (?Post $record): bool => filled($record?->parse_error)),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\ImageColumn::make('preview_image')->label('Превью'),
                TextColumn::make('title')->label('Заголовок')->sortable()->wrap(),
                Tables\Columns\IconColumn::make('translation_incomplete')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success')
                    ->label('Перевод')
                    ->tooltip(fn (Post $record): ?string => $record->translation_incomplete
                        ? 'Часть блоков осталась без перевода — требует ревью'
                        : null)
                    ->sortable(),
                TextColumn::make('parse_status')
                    ->label('Парсинг')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Post::PARSE_STATUS_OK => 'Разобран',
                        Post::PARSE_STATUS_FAILED => 'Ошибка',
                        default => 'Вручную',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        Post::PARSE_STATUS_OK => 'success',
                        Post::PARSE_STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    // Причина сбоя выводится текстом под бейджем, а не в
                    // тултипе: смысл фичи — увидеть её сразу в списке.
                    ->description(fn (Post $record): ?string => $record->parse_error ?: null)
                    ->wrap()
                    ->sortable(),
                Tables\Columns\IconColumn::make('published')
                    ->label('Опубликовано')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')->label('Дата создания')->dateTime('d.m.Y H:i')->sortable(),
                // Когда пост последний раз прогоняли через парсер — в отличие
                // от created_at, который хранит дату публикации оригинала.
                TextColumn::make('parsed_at')
                    ->label('Спарсен')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('views_count')->label('Просмотры')->counts('views')->sortable(),
                // TextColumn::make('category.title')->label('Category')->sortable()->url(fn(Post $record) => CategoryResource::getUrl('edit', ['record' => $record->category])),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\Filter::make('published')->label('Опубликован'),
                Tables\Filters\Filter::make('parse_failed')
                    ->label('С ошибкой парсинга')
                    ->query(fn (Builder $query): Builder => $query->parseFailed())
                    ->toggle(),
                Tables\Filters\Filter::make('created_at')->label('Дата создания')->form([
                    Forms\Components\DatePicker::make('created_from')->label('С'),
                    Forms\Components\DatePicker::make('created_until')->label('По'),
                ])
                    ->query(function (Builder $query, array $data) {
                        return $query->when($data['created_from'], fn ($query) => $query->whereDate('created_at', '>=', $data['created_from']))
                            ->when($data['created_until'], fn ($query) => $query->whereDate('created_at', '<=', $data['created_until']));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::reparseAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Перезапуск парсинга статьи по её url — типовой сценарий после того,
     * как для домена завели селектор в SiteSelectorResource: сам селектор
     * на посте не хранится (PostService затирает его при сохранении), поэтому
     * определяем его заново по url через ContentSelectorResolver.
     *
     * Джоба уходит в очередь: скачивание + перевод статьи занимают десятки
     * секунд, в HTTP-запросе админки это ждать нельзя.
     */
    public static function reparseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reparse')
            ->label('Перепарсить')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Post $record): bool => filled($record->url))
            ->requiresConfirmation()
            ->modalHeading('Перепарсить статью')
            ->modalDescription('Страница будет заново скачана и разобрана в фоне. Если разбор снова не удастся, уже сохранённый контент не потеряется — обновится только причина сбоя.')
            ->modalSubmitActionLabel('Отправить в очередь')
            ->action(fn (Post $record) => self::dispatchReparse($record));
    }

    /**
     * Общее тело действия «Перепарсить» для табличного экшена и для
     * заголовка страницы редактирования (у Filament это разные классы
     * Action — Tables\Actions и Actions соответственно).
     */
    public static function dispatchReparse(Post $post): void
    {
        StorePostJob::dispatch([
            'url' => $post->url,
            'selector' => app(ContentSelectorResolver::class)->resolve($post->url),
            'tag_ids' => [],
            'translate' => null,
        ]);

        Notification::make()
            ->title('Статья отправлена на перепарсинг')
            ->body('Результат появится в колонке «Парсинг» после обработки очереди.')
            ->success()
            ->send();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TagsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
