<?php

namespace App\Models;

use App\Jobs\GenerateImageVariantsJob;
use App\Jobs\SubmitUrlToIndexNow;
use App\Service\HtmlSanitizerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Значение ?lang=, включающее показ исходного текста. Язык у всех
     * первоисточников один — дайджест PHP Weekly англоязычный.
     */
    public const ORIGINAL_LANG = 'en';

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
        'is_news',
        'url',
        'selector',
        'translate',
        'translation_incomplete',
        // Каким движком переведена статья: подмена основного движка запасным
        // происходит молча, и без этой отметки деградация невидима.
        'translated_by',
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

    public function tags(): BelongsToMany
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
        'is_news' => 'boolean',
        'translation_incomplete' => 'boolean',
        // Без каста в индекс Meilisearch уходит целочисленная 1, и фильтр
        // Post::search(...)->where('published', true) не находит ничего
        // (сравнение с булевым true не совпадает с числом).
        'published' => 'boolean',
        'published_at' => 'datetime',
        'parsed_at' => 'datetime',
        // В $fillable намеренно не входит, как и published_at: счётчик ведёт
        // NewsImportService через increment(), и попасть сюда из массива
        // данных разбора он не должен — иначе очередной StorePostJob молча
        // обнулял бы историю попыток вместе с сохранением заглушки.
        'parse_attempts' => 'integer',
    ];

    /**
     * Уменьшенные копии превью для srcset генерируются здесь, а не в
     * PostService или StorePostJob: обложку задаёт ещё и админка Filament,
     * которая сохраняет модель напрямую, и мимо любого сервиса.
     */
    protected static function booted(): void
    {
        /*
         * Момент первой публикации фиксируется здесь, а не в контроллере или
         * ресурсе Filament: снять галочку черновика можно из формы поста, из
         * массового действия и из tinker, и в каждом месте об этом пришлось бы
         * помнить.
         *
         * Дата ставится один раз: снятая и возвращённая публикация не должна
         * выглядеть новой — иначе темп публикаций накрутится редактированием
         * старых постов.
         */
        static::saving(function (self $post) {
            if (! $post->published || $post->published_at !== null) {
                return;
            }

            /*
             * У существующей записи дата берётся из updated_at, а не из now().
             * Миграция проставила published_at по updated_at, но у части старых
             * постов он сам пуст — и без этой ветки первое же сохранение
             * (скажем, `post:translate`) выдало бы статью, вышедшую весной, за
             * опубликованную сегодня, накрутив недельный темп.
             */
            $post->published_at = $post->exists
                ? ($post->updated_at ?? $post->created_at ?? now())
                : now();
        });

        static::saved(function (self $post) {
            // main_image обычно тот же файл, что и preview_image, но в
            // админке их можно задать разными — тогда обложке статьи нужны
            // свои варианты.
            foreach (['preview_image', 'main_image'] as $field) {
                if ($post->wasChanged($field) && filled($post->$field)) {
                    // afterCommit обязателен: сохранение идёт внутри транзакции
                    // PostService::store(), а очередь настроена с
                    // after_commit = false — без этого воркер мог схватить
                    // задачу раньше, чем коммит сделает файл «официальным».
                    GenerateImageVariantsJob::dispatch($post->$field)->afterCommit();
                }
            }
        });

        static::saved(function (self $post) {
            // Публикация — сигнал для IndexNow. Хук здесь, а не в админке,
            // по той же причине, что published_at выше: опубликовать пост
            // можно из формы, тогглом в списке, массовым действием и из
            // tinker — и везде поисковик должен узнать о странице.
            //
            // wasChanged('published') отсекает правки уже опубликованного:
            // IndexNow про обновления контента, но перепарсинг и так
            // пересохраняет пост — без условия каждая правка гоняла бы пинг.
            if (! $post->published || ! $post->wasChanged('published')) {
                return;
            }

            SubmitUrlToIndexNow::dispatch($post->permalink())->afterCommit();
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

    /**
     * Что уходит в Meilisearch.
     *
     * Без этого метода Scout берёт toArray(), то есть отправляет модель
     * целиком — со всеми служебными полями парсера.
     *
     * content_orig раньше в индекс не шёл: «искать по английскому оригиналу на
     * русском сайте незачем». Это оказалось неверно ровно для той аудитории,
     * ради которой блог и существует: термины вроде «queue worker», «PSR-15»
     * или «readonly properties» в переводе остаются английскими не всегда, и
     * запрос по ним не находил статью, которая целиком про них. Оригинал уже
     * лежит в БД, так что цена — только место в индексе.
     *
     * Разметку вырезаем: имена тегов и атрибуты не должны попадать в поисковый
     * индекс, иначе запрос вроде «href» находит все статьи со ссылками.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'code' => (string) $this->code,
            'content' => $this->plainText($this->content),
            'content_orig' => $this->plainText($this->content_orig),
            'category' => (string) ($this->category?->title ?? ''),
            'created_at' => $this->created_at?->timestamp,
            // Обязателен, хотя shouldBeSearchable() и так пускает в индекс
            // только опубликованные: SearchController страхуется фильтром
            // ->where('published', true) на случай, если пост сняли с
            // публикации уже после индексации. Без поля в документе этот
            // фильтр не совпадает ни с чем и выдача становится пустой.
            // Именно bool, а не 1: сравнение с числом Meilisearch не проходит.
            'published' => (bool) $this->published,
        ];
    }

    /**
     * Текст без разметки: теги заменяются пробелом (а не вырезаются встык,
     * иначе на границах блоков склеиваются слова — «в PHP 8.Ключевое»),
     * пробельные последовательности схлопываются.
     */
    private function plainText(?string $html): string
    {
        $text = preg_replace('#<[^>]+>#', ' ', (string) $html) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text)));
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
     * content_orig — такой же чужой скрейпленный HTML, и с 14.08.2026 он
     * рендерится на странице ?lang=en. Мутатор закрывает новые записи, старые
     * (до 27.07.2026) — posts:resanitize и санитайзинг на выводе, см.
     * originalBody().
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
        return $this->excerptFrom($this->content, $length);
    }

    /**
     * То же для исходного текста: страница с ?lang=en описывает английское
     * тело, а не перевод — иначе ссылкой на оригинал в мессенджер уезжало бы
     * русское описание чужого английского текста.
     */
    public function originalExcerpt(int $length = 160): string
    {
        return $this->excerptFrom($this->content_orig, $length);
    }

    private function excerptFrom(?string $source, int $length): string
    {
        $html = preg_replace('#<(pre|code|table)\b[^>]*>.*?</\1>#is', ' ', (string) $source) ?? '';
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

    /**
     * Число слов в тексте статьи.
     *
     * Считается юникодным паттерном по буквам и цифрам, а не
     * str_word_count(): тот по умолчанию считает буквами только ASCII, и на
     * русском тексте возвращал единицы — именно этот мусор уезжал в wordCount
     * JSON-LD на странице поста. Общий метод, а не копия паттерна в шаблоне:
     * подсчёт нужен и времени чтения, и разметке, и расходиться они не должны.
     */
    public function wordCount(): int
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', strip_tags((string) $this->content));
    }

    public function readingTimeMinutes(): int
    {
        return max(1, (int) ceil($this->wordCount() / self::WORDS_PER_MINUTE));
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

    /**
     * Собственный адрес материала.
     *
     * Статьи живут на /posts/{code}, новости — на /news/{code}, и Post\
     * ShowController отдаёт 301 при обращении по «чужому» адресу. Значит,
     * route('post.show') на новость — это ссылка в редирект: лишний хоп для
     * пользователя и размытый вес для поисковика. Помнить про is_news в
     * каждом шаблоне не выйдет — карты сайта это уже проходили, — поэтому
     * адрес считает модель.
     *
     * Имя не url(): колонка `posts.url` уже занята адресом первоисточника
     * (по ней же стоит UNIQUE для дедупликации новостей). Одноимённый метод
     * Eloquent принимает за объявление связи и валит `$post->url` в
     * LogicException «must return a relationship instance» — ловится не
     * здесь, а в админке Filament при создании поста.
     */
    public function permalink(): string
    {
        return route($this->is_news ? 'news.show' : 'post.show', $this->code);
    }

    /**
     * Адрес страницы с исходным (английским) текстом.
     *
     * Отдельный query-параметр, а не свой маршрут: материал тот же самый,
     * различается только язык тела. Индексироваться этот адрес не должен —
     * Post\ShowController отдаёт на нём noindex и canonical на перевод, иначе
     * получилась бы англоязычная копия чужой статьи в выдаче.
     */
    public function originalPermalink(): string
    {
        return $this->permalink().'?'.http_build_query(['lang' => self::ORIGINAL_LANG]);
    }

    /**
     * Есть ли что показывать в оригинале.
     *
     * content_orig заполняет только парсер, поэтому у постов, написанных
     * руками, и у всего, что заведено до 22.02.2026, его нет.
     */
    public function hasOriginal(): bool
    {
        return filled($this->content_orig);
    }

    /**
     * Исходный текст, пригодный для рендера через {!! !!}.
     *
     * Санитайзинг именно на выводе, хотя мутатор уже чистит запись: колонка
     * пишется с 22.02.2026, а setContentOrigAttribute появился только
     * 27.07.2026 — то есть в БД лежат сотни записей с необработанным HTML
     * страницы-источника (на проде у двух из них уцелел <iframe>). Полагаться
     * на то, что posts:resanitize прогнали на каждом окружении, для
     * stored XSS нельзя: цена ошибки — исполнение чужого скрипта у читателя.
     *
     * Результат кладётся в кэш до следующей правки поста: HTMLPurifier на
     * статью в десятки килобайт — это десятки миллисекунд, а текст между
     * сохранениями не меняется.
     */
    public function originalBody(): string
    {
        return Cache::remember(
            'post:'.$this->getKey().':original-body:'.($this->updated_at?->timestamp ?? 0),
            now()->addDay(),
            fn (): string => app(HtmlSanitizerService::class)
                ->sanitize((string) $this->content_orig, stripRemoteImages: true),
        );
    }

    /**
     * Адрес первоисточника, но только если по нему можно безопасно перейти.
     *
     * url приходит со страницы стороннего дайджеста, и Blade экранирует
     * кавычки, но не схему: `javascript:` в href остался бы рабочим — и в
     * админке, где CSP ослаблена до 'unsafe-inline', в первую очередь.
     * Проверка на выводе, а не только на входе: посты с чужой схемой могли
     * попасть в БД раньше.
     */
    public function sourceUrl(): ?string
    {
        return Str::startsWith((string) $this->url, ['http://', 'https://'])
            ? $this->url
            : null;
    }

    /** Домен первоисточника — «medium.com» вместо простыни из адреса. */
    public function sourceHost(): ?string
    {
        return $this->sourceUrl() === null
            ? null
            : (parse_url($this->sourceUrl(), PHP_URL_HOST) ?: null);
    }

    /**
     * Новости и статьи — две ленты одного типа записей. Разделяем скоупами,
     * чтобы нигде не пришлось помнить про `where('is_news', ...)` руками.
     */
    public function scopeNews(Builder $query): Builder
    {
        return $query->where('is_news', true);
    }

    public function scopeArticles(Builder $query): Builder
    {
        return $query->where('is_news', false);
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
