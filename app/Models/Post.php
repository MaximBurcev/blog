<?php

namespace App\Models;

use App\Service\HtmlSanitizerService;
use App\Service\ImageVariantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable, SoftDeletes;

    private const WORDS_PER_MINUTE = 200;

    /** Контент статьи успешно извлечён. */
    public const PARSE_STATUS_OK = 'ok';

    /** Пост создан, но контент вытащить не удалось — причина в parse_error. */
    public const PARSE_STATUS_FAILED = 'failed';

    protected $table = 'posts';

    protected $fillable = [
        'title',
        'content',
        'content_orig',
        'category_id',
        'preview_image',
        'main_image',
        'code',
        'published',
        'url',
        'selector',
        'translate',
        'translation_incomplete',
        'parse_status',
        'parse_error',
        'parsed_at',
        // Дата публикации оригинальной статьи (StorePostJob вытаскивает её
        // со страницы-источника) — created_at должен отражать, когда пост
        // вышел у источника, а не когда его сюда затащил скрейпер. Без
        // явного присвоения Eloquent молча подставил бы now() при insert.
        'created_at',
    ];

    protected $with = ['category'];

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tags', 'post_id', 'tag_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }

    protected $casts = [
        'translation_incomplete' => 'boolean',
        // Без каста в индекс Meilisearch уходит целочисленная 1, и фильтр
        // Post::search(...)->where('published', true) не находит ничего
        // (сравнение с булевым true не совпадает с числом).
        'published' => 'boolean',
        'parsed_at' => 'datetime',
    ];

    /**
     * Уменьшенные копии превью для srcset генерируются здесь, а не в
     * PostService или StorePostJob: обложку задаёт ещё и админка Filament,
     * которая сохраняет модель напрямую, и мимо любого сервиса.
     */
    protected static function booted(): void
    {
        static::saved(function (self $post) {
            // main_image обычно тот же файл, что и preview_image, но в
            // админке их можно задать разными — тогда обложке статьи нужны
            // свои варианты.
            foreach (['preview_image', 'main_image'] as $field) {
                if ($post->wasChanged($field) && filled($post->$field)) {
                    app(ImageVariantService::class)->generate($post->$field);
                }
            }
        });
    }

    /**
     * Имя намеренно отличается от скоупа parseFailed(): одноимённый метод
     * экземпляра перехватывал бы статический вызов Post::parseFailed()
     * до Eloquent-скоупа (PHP звал бы его статически и падал).
     */
    public function hasParseError(): bool
    {
        return $this->parse_status === self::PARSE_STATUS_FAILED;
    }

    /**
     * В индекс идут только опубликованные посты с контентом. Черновики
     * попадать в поиск не должны по двум причинам: их публичный URL отдаёт
     * 404 (Post\ShowController фильтрует published), а заглушки от
     * неудавшегося парсинга — это пустая карточка без текста.
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) $this->published && filled($this->content);
    }

    public function scopeParseFailed(Builder $query): Builder
    {
        // qualifyColumn — список постов в админке джойнится с categories,
        // неквалифицированное имя колонки там неоднозначно читается.
        return $query->where($query->qualifyColumn('parse_status'), self::PARSE_STATUS_FAILED);
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function likesCount()
    {
        return $this->likes()->count();
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostView::class);
    }

    public function viewsCount(): int
    {
        return $this->views()->count();
    }

    public function viewsLabel(?int $count = null): string
    {
        $count ??= $this->viewsCount();

        return $count.' '.$this->pluralViews($count);
    }

    private function pluralViews(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'просмотр';
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return 'просмотра';
        }

        return 'просмотров';
    }

    /**
     * Content — чужой скрейпленный HTML, рендерится через {!! !!} на
     * публичной странице и в Summernote в админке без экранирования.
     * Санитайзинг вынесен на уровень мутатора модели (а не в PostService),
     * чтобы его нельзя было обойти вторым путём записи — Filament-ресурсом
     * PostResource, который сохраняет форму напрямую через Eloquent.
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = $value !== null
            ? app(HtmlSanitizerService::class)->sanitize($value)
            : $value;
    }

    /**
     * content_orig нигде сейчас не рендерится, но хранится как обычный
     * скрейпленный HTML — санитайзим по той же причине, что и content:
     * мина для будущего diff/API-эндпоинта, который однажды выведет его как
     * есть.
     */
    public function setContentOrigAttribute(?string $value): void
    {
        $this->attributes['content_orig'] = $value !== null
            ? app(HtmlSanitizerService::class)->sanitize($value)
            : $value;
    }

    /**
     * Короткое описание статьи для meta description, og:description и RSS.
     *
     * Из текста выбрасываются блоки кода и таблицы: без этого в описание
     * попадали куски листингов («…воссоздадим оператор ниже:$paymentS…»).
     * Теги заменяются пробелом, а не удаляются, иначе соседние блоки
     * слипались («появилось в PHP 8.Ключевое слово»). Обрезка — по границе
     * слова, а не посреди него.
     */
    public function excerpt(int $length = 160): string
    {
        $html = preg_replace('#<(pre|code|table)\b[^>]*>.*?</\1>#is', ' ', (string) $this->content) ?? '';
        $text = html_entity_decode(preg_replace('/<[^>]+>/u', ' ', $html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // Str::limit(preserveWords:) появился только в Laravel 11 — режем сами
        // по последнему пробелу, попутно убирая повисшую пунктуацию.
        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, " \t\n\r\0\x0B,.;:—–-").'…';
    }

    public function readingTimeMinutes(): int
    {
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', strip_tags((string) $this->content));

        return max(1, (int) ceil($wordCount / self::WORDS_PER_MINUTE));
    }

    public function readingTimeLabel(): string
    {
        $minutes = $this->readingTimeMinutes();

        return $minutes.' '.$this->pluralMinutes($minutes).' чтения';
    }

    private function pluralMinutes(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'минута';
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return 'минуты';
        }

        return 'минут';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeRelatedTo(Builder $query, Post $post, int $limit = 4): Builder
    {
        $tagIds = $post->tags->pluck('id');

        return $query->where('id', '!=', $post->id)
            ->published()
            ->when($tagIds->isNotEmpty(), fn (Builder $q) => $q
                ->withCount(['tags as shared_tags_count' => fn ($t) => $t->whereIn('tags.id', $tagIds)])
                ->orderByDesc('shared_tags_count'))
            ->when($post->category_id, fn (Builder $q) => $q
                ->orderByRaw('category_id = ? DESC', [$post->category_id]))
            ->orderByDesc('created_at')
            ->limit($limit);
    }
}
