<?php

namespace App\Filament\Pages;

use App\Jobs\StorePostJob;
use App\Models\Post;
use App\Support\ContentSelectorResolver;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Создание поста из чужой статьи: единственное поле — ссылка. Заголовок,
 * контент, обложка, дата, категория и теги приезжают из самой статьи
 * (StorePostJob), селектор блока с текстом подбирается по домену
 * (ContentSelectorResolver), поэтому руками его вводить не нужно — в
 * отличие от прежней формы /admin/posts/add.
 */
class ParsePost extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $navigationLabel = 'Спарсить пост';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.parse-post';

    protected static ?string $title = 'Спарсить пост по ссылке';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('url')
                    ->label('Ссылка на статью')
                    ->required()
                    ->url()
                    ->maxLength(2048)
                    ->placeholder('https://example.com/blog/some-article')
                    ->autofocus()
                    // live, чтобы подсказка про селектор и про уже
                    // существующий пост обновлялась сразу после ввода ссылки
                    ->live(onBlur: true)
                    ->helperText(fn (?string $state): string => $this->urlHint($state)),
            ])
            ->statePath('data');
    }

    public function parse(): void
    {
        $url = trim($this->form->getState()['url']);

        StorePostJob::dispatch([
            'url' => $url,
            'selector' => app(ContentSelectorResolver::class)->resolve($url),
            'tag_ids' => [],
            'translate' => null,
        ]);

        Notification::make()
            ->title('Статья отправлена на парсинг')
            ->body('Пост появится в списке после обработки очереди. Если разобрать страницу не удастся, он всё равно будет создан — с причиной сбоя в колонке «Парсинг».')
            ->success()
            ->send();

        $this->form->fill();
    }

    /**
     * Подсказка под полем: какой селектор применится к этому домену и не
     * заведён ли уже пост с такой ссылкой (повторный парсинг обновит его,
     * а не создаст дубль).
     */
    private function urlHint(?string $url): string
    {
        if (blank($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Заголовок, текст, обложку, дату, категорию и теги парсер возьмёт из самой статьи. Пост создаётся черновиком — опубликовать нужно вручную.';
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $resolved = app(ContentSelectorResolver::class)->resolveWithSource($url);

        $hint = match ($resolved['source']) {
            'admin', 'config' => "Для {$host} задано правило: селектор «{$resolved['selector']}».",
            default => "Для {$host} правила нет — применится общий селектор «{$resolved['selector']}». Если контент не найдётся, пост создастся с причиной сбоя, а правило можно добавить в разделе «Селекторы сайтов».",
        };

        $existing = Post::where('url', $url)->first();

        return $existing
            ? $hint." Пост с такой ссылкой уже есть (#{$existing->id} «{$existing->title}») — он будет обновлён, а не создан заново."
            : $hint;
    }
}
