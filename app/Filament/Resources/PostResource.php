<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Filament\Resources\PostResource\RelationManagers;
use App\Jobs\StorePostJob;
use App\Models\Category;
use App\Models\Post;
use App\Service\Translation\GeminiTranslator;
use App\Support\ContentSelectorResolver;
use App\Support\PostCode;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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
                // Пост, заведённый парсером, без этой ссылки не отличить от
                // написанного руками: сам url в форме больше нигде не виден.
                Forms\Components\Placeholder::make('source_url')
                    ->label('Источник')
                    // Кликабельной ссылку делаем только для http(s), и гард на
                    // схему живёт в Post::sourceUrl(): тот же адрес выводит
                    // публичная страница поста, и правило там обязано быть
                    // одно. Здесь оно критичнее — в панели CSP намеренно
                    // ослаблена до 'unsafe-inline'.
                    ->content(fn (?Post $record): HtmlString => new HtmlString(
                        $record?->sourceUrl()
                            ? sprintf(
                                '<a href="%1$s" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">%1$s</a>',
                                e($record->sourceUrl())
                            )
                            : e((string) $record?->url)
                    ))
                    ->visible(fn (?Post $record): bool => filled($record?->url)),
                TextInput::make('title')->required()->reactive()
                    ->afterStateUpdated(function (string $operation, $set, $state) {
                        // Только на создании: у существующего поста code — это
                        // его публичный адрес, и правка заголовка молча уводила
                        // статью на новый URL (старый начинал отдавать 404
                        // вместе с накопленными позициями и внешними ссылками).
                        if ($operation !== 'create') {
                            return;
                        }

                        $set('code', PostCode::fromTitle($state));
                    })->label('Заголовок'),
                TextInput::make('code')->required()->label('Код (slug)')
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? 'Подставляется из заголовка, можно поправить.'
                        : 'Часть публичного адреса статьи. Меняйте осознанно: старый адрес начнёт отдавать 404.'),
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
                // Новость — тот же пост, просто в другой ленте: /news вместо
                // главной. Разбор, перевод и страница у неё общие со статьями.
                Forms\Components\Checkbox::make('is_news')->label('Новость')
                    ->helperText('Попадёт в раздел «Новости» и не будет показана в общей ленте.'),
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
                TextColumn::make('title')->label('Заголовок')->sortable()->wrap()
                    // Домен источника прямо под заголовком: в списке важно
                    // отличить спарсенные посты от заведённых вручную.
                    ->description(fn (Post $record): ?string => $record->sourceHost()),
                TextColumn::make('url')
                    ->label('Источник')
                    ->url(fn (Post $record): ?string => $record->sourceUrl())
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // Каким движком переведено. Основной может молча уступить
                // запасному (сеть, квота, регион), и без этой колонки узнать,
                // какие статьи стоит перевести заново, можно было бы только
                // запросом в базу.
                TextColumn::make('translated_by')
                    ->label('Движок')
                    ->badge()
                    // Значение — это имя модели («gemini-3.5-flash»), а у
                    // исторических записей просто «gemini»: с 24.08.2026 движки
                    // различаются по модели, потому что квота у Google своя на
                    // каждую. В бейдже показываем «LLM», модель — в подсказке,
                    // иначе колонка превращается в столбик версий.
                    ->color(fn (?string $state): string => match (true) {
                        $state !== null && str_starts_with($state, GeminiTranslator::PROVIDER) => 'success',
                        $state === 'google' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        $state !== null && str_starts_with($state, GeminiTranslator::PROVIDER) => 'LLM',
                        $state === 'google' => 'скрейпер',
                        $state === 'none' => 'не переведён',
                        default => '—',
                    })
                    ->tooltip(fn (Post $record): ?string => match (true) {
                        $record->translated_by === 'google' => 'Запасной движок: LLM была недоступна. Стоит перевести заново',
                        $record->translated_by !== null && str_starts_with($record->translated_by, GeminiTranslator::PROVIDER) => $record->translated_by,
                        default => null,
                    })
                    // Видна сразу, а не за переключателем колонок: подмена
                    // основного движка запасным происходит молча, и заметить её
                    // можно только здесь.
                    ->toggleable()
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
                Tables\Filters\TernaryFilter::make('is_news')
                    ->label('Новость')
                    ->trueLabel('Только новости')
                    ->falseLabel('Только статьи'),
                // Был Filter::make('published') без ->query() — чекбокс ничего
                // не фильтровал. Тернарный фильтр даёт оба состояния сразу.
                Tables\Filters\TernaryFilter::make('published')
                    ->label('Публикация')
                    ->placeholder('Все')
                    ->trueLabel('Опубликован')
                    ->falseLabel('Не опубликован'),
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
            // Передаём текущие категорию и теги, а не пустоту: PostService
            // запускает автодетект только когда значение пустое, и с
            // 'tag_ids' => [] перепарсинг затирал расставленное вручную
            // результатом детектора (теги — через sync(), то есть начисто).
            'category_id' => $post->category_id,
            'tag_ids' => $post->tags()->pluck('tags.id')->all(),
            'translate' => null,
        ]);

        Notification::make()
            ->title('Статья отправлена на перепарсинг')
            // Честно про ShouldBeUnique: если эта же ссылка уже в очереди или
            // разбирается прямо сейчас, повторная постановка молча отбрасывается
            // — рапортовать об успехе в таком случае было бы враньём.
            ->body('Результат появится в колонке «Парсинг» после обработки очереди. Если статья уже стоит в очереди, повторная постановка будет пропущена — состояние видно в виджете на главной странице панели.')
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
