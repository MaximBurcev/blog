<?php

namespace App\Jobs;

use App\DataTransferObjects\PostData;
use App\Exceptions\TransientFetchException;
use App\Models\Post;
use App\Service\ChallengeSolverClient;
use App\Service\ContentImageService;
use App\Service\DiagramTranslatorService;
use App\Service\PostService;
use App\Service\Translation\TranslationQualityChecker;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use App\Support\ContentSelectorResolver;
use App\Support\FeedArticleLocator;
use App\Support\PinnedTarget;
use App\Support\SelectorXPathBuilder;
use App\Support\UrlSafetyChecker;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ShouldBeUnique — PostService::store() делает updateOrCreate() по url, но
 * это защита только от повторного handle() ОДНОЙ и той же джобы (retry).
 * Без distributed lock две параллельные джобы по одному и тому же url (два
 * воркера queue:work, либо повторный ParseReleaseJob до того как первая
 * успела закоммититься) всё ещё могут одновременно пройти проверку
 * "существует ли Post с таким url" и создать дубликат.
 *
 * Второй рубеж — UNIQUE-индекс posts_url_unique (миграция 2026_08_04_100000):
 * лок держится на файловом кэше и потому не распределённый, а индекс делает
 * дубликат физически невозможным независимо от того, сколько воркеров и на
 * скольких инстансах работают.
 */
class StorePostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_REDIRECTS = 5;

    /**
     * 420, а не 300: на боевом (1 ядро) разбор статьи с переводом и
     * скачиванием картинок занимает до ~210 с, а обход antibot-проверки
     * через headless-браузер добавляет сверху ещё ~20 с. Прежнего потолка
     * хватало впритык, и длинная статья с challenge выбивала джобу по
     * таймауту — то есть превращалась в заглушку на ровном месте.
     *
     * Инвариант: queue.connections.database.retry_after обязан оставаться
     * больше этого значения (сейчас 480).
     */
    public int $timeout = 420;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * Лок живёт заметно меньше полного цикла ретраев (300с × 3 попытки плюс
     * backoff до 900с) осознанно.
     *
     * Час его удержания означал, что убитый воркер — OOM, рестарт контейнера,
     * деплой — оставляет залипший лок, и «Перепарсить» для этой статьи молча
     * не работает целый час. Дороже, чем повторный разбор: от дубликатов
     * постов теперь защищает UNIQUE-индекс posts_url_unique, а не только лок,
     * так что худшее последствие пересечения — лишняя работа, а не мусор
     * в данных.
     */
    public int $uniqueFor = 900;

    private $data;

    private PostService $service;

    private ContentImageService $imageService;

    private UrlSafetyChecker $urlSafetyChecker;

    private DiagramTranslatorService $diagramTranslator;

    private Translator $translator;

    /**
     * Сколько фрагментов осталось без перевода в текущем прогоне.
     *
     * Раньше счётчик приезжал из трейта TranslatesNodes вместе со всей
     * машинерией поблочного перевода. Переводом занимается общий слой, и от
     * трейта здесь нужен был только этот счётчик — держать ради него весь
     * трейт значит тащить в джобу мёртвый код: маскировку плейсхолдерами,
     * разбор инлайн-разметки и клиент скрейпера.
     */
    private int $translationFallbacks = 0;

    private function hasTranslationFallbacks(): bool
    {
        return $this->translationFallbacks > 0;
    }

    /**
     * Эвристики качества перевода поверх счётчика фолбэков: движок мог
     * отчитаться об успехе и всё же оборвать текст или оставить часть блоков
     * в оригинале. Сравнивается то, что реально уйдёт в пост — content_orig и
     * content; без оригинала (ручной пост, неудачный разбор) мерить нечего.
     */
    private function translationNeedsReview(): bool
    {
        $orig = (string) ($this->data['content_orig'] ?? '');

        return $orig !== ''
            && TranslationQualityChecker::fromConfig()->reviewReason(
                $orig,
                (string) ($this->data['content'] ?? '')
            ) !== null;
    }

    /**
     * Причина, по которой не удалось скачать страницу — заполняется внутри
     * fetchHtml(), чтобы handle() мог записать её в parse_error поста, а не
     * только в лог.
     */
    private ?string $fetchError = null;

    public function __construct($data)
    {
        //
        $this->data = $data;
    }

    /**
     * url — естественный ключ поста (см. PostService::store). Если его нет
     * (ручная загрузка через html_file), дедуплицируем по самому html_file —
     * иначе все такие джобы схлопнулись бы в один uniqueId и блокировали
     * друг друга.
     */
    public function uniqueId(): string
    {
        return md5($this->data['url'] ?: ($this->data['html_file'] ?? serialize($this->data)));
    }

    public function handle(PostService $service, ContentImageService $imageService, UrlSafetyChecker $urlSafetyChecker, DiagramTranslatorService $diagramTranslator, Translator $translator): void
    {
        $this->service = $service;
        $this->imageService = $imageService;
        $this->urlSafetyChecker = $urlSafetyChecker;
        $this->diagramTranslator = $diagramTranslator;
        $this->translator = $translator;

        $failure = null;

        try {
            $dom = new DOMDocument;

            $html = $this->fetchHtml();

            Log::debug('StorePostJob: response', [
                'length' => strlen((string) $html),
                'has_crayons_article' => str_contains((string) $html, 'crayons-article__body'),
            ]);

            if ($html === false) {
                $failure = $this->fetchError ?? 'Не удалось скачать страницу статьи';
            } elseif (empty($html)) {
                throw new TransientFetchException('Источник вернул пустой ответ');
            } elseif ($this->isChallengePage($html)) {
                // Антибот-заглушка часто уходит сама: смена выходного адреса,
                // спад нагрузки на защите источника. Отдаём очереди на retry.
                throw new TransientFetchException('Вместо статьи отдана антибот-заглушка (Cloudflare)');
            } else {
                @$dom->loadHTML($html);

                $this->applyOgImage($dom);
                $this->applyPublishedDate($dom);

                $h1 = $this->resolveH1($dom);

                if ($h1->length > 0) {
                    $this->extractArticle($dom, $h1);
                }
            }
        } catch (TransientFetchException $exception) {
            // Наружу: очередь запланирует retry по $backoff. Заглушку поста
            // создаст failed(), когда попытки закончатся.
            Log::warning('StorePostJob: transient failure, retry', [
                'url' => $this->data['url'] ?? null,
                'attempt' => $this->attempts(),
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('StorePostJob error: '.$exception->getMessage(), [
                'class' => get_class($exception),
                'line' => $exception->getLine(),
            ]);

            $failure = 'Ошибка при разборе страницы: '.$exception->getMessage();
        }

        if ($failure === null && empty($this->data['title'])) {
            $failure = 'На странице не найден заголовок статьи (<h1>/<title>)';
        }

        if ($failure === null && empty($this->data['content'])) {
            $failure = sprintf(
                'Не удалось извлечь контент по селектору «%s» — вероятно, у источника другая вёрстка',
                $this->data['selector'] ?? ''
            );
        }

        // Пост создаётся в любом случае: неразобранная ссылка должна быть
        // видна в админке (с причиной сбоя), а не теряться в laravel.log.
        // Публикации это не мешает — published по умолчанию false.
        $this->applyParseResult($failure);

        $this->data['translation_incomplete'] = $this->hasTranslationFallbacks()
            || $this->translationNeedsReview();

        $this->service->store(PostData::fromArray($this->data));
    }

    /**
     * Ретраи закончились (или джоба не уложилась в $timeout). Ссылка не должна
     * потеряться молча: создаём тот же пост-заглушку, что и при постоянном
     * сбое, но с пометкой, сколько попыток было потрачено.
     *
     * Вызывается на свежем экземпляре с исходным payload, поэтому $this->data
     * здесь — то, с чем джобу поставили в очередь, без наработок handle().
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StorePostJob: attempts exhausted', [
            'url' => $this->data['url'] ?? null,
            'class' => get_class($exception),
            'reason' => $exception->getMessage(),
        ]);

        $this->applyParseResult(sprintf(
            'Не удалось разобрать за %d попыток: %s',
            $this->tries,
            $exception->getMessage()
        ));

        $this->data['content'] = $this->data['content'] ?? '';

        try {
            app(PostService::class)->store(PostData::fromArray($this->data));
        } catch (\Throwable $storeException) {
            // Уже в failed(): бросать отсюда некуда, кроме лога.
            Log::error('StorePostJob: failed() could not persist stub post', [
                'url' => $this->data['url'] ?? null,
                'error' => $storeException->getMessage(),
            ]);
        }
    }

    /**
     * Записывает исход парсинга в данные поста: причину сбоя (или её
     * снятие, если на этот раз всё разобралось) и время попытки.
     */
    private function applyParseResult(?string $failure): void
    {
        $this->data['parsed_at'] = now()->toDateTimeString();

        if ($failure === null) {
            $this->data['parse_status'] = Post::PARSE_STATUS_OK;
            // Пустая строка, а не null: PostData::toArray() отбрасывает null,
            // и ошибка от прошлой неудачной попытки осталась бы на посте.
            $this->data['parse_error'] = '';

            return;
        }

        Log::warning('StorePostJob: parse failed', [
            'url' => $this->data['url'] ?? null,
            'reason' => $failure,
        ]);

        $this->data['parse_status'] = Post::PARSE_STATUS_FAILED;
        $this->data['parse_error'] = $failure;

        if (empty($this->data['title'])) {
            $this->data['title'] = $this->fallbackTitle();
        }
    }

    /**
     * Заголовок для поста, у которого не удалось разобрать страницу:
     * текст ссылки со страницы релиза, иначе — читаемый огрызок URL
     * (title в БД NOT NULL, а пустой заголовок в списке админки
     * невозможно опознать).
     */
    private function fallbackTitle(): string
    {
        if (! empty($this->data['link_text'])) {
            return Str::limit(trim($this->data['link_text']), 250, '');
        }

        $url = (string) ($this->data['url'] ?? '');

        if ($url === '') {
            return Str::limit('Не разобрано: '.basename((string) ($this->data['html_file'] ?? 'без источника')), 250, '');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $slug = trim(str_replace(['-', '_', '/'], ' ', (string) parse_url($url, PHP_URL_PATH)));

        return Str::limit(trim($host.' '.$slug) ?: $url, 250, '');
    }

    /**
     * CSS-класс контейнера в HTML, который мы синтезируем из RSS. Значение
     * попадает в data['selector'], поэтому дальше по пайплайну статья из
     * фида обрабатывается ровно так же, как скачанная страница.
     */
    private const FEED_CONTENT_CLASS = 'feed-article-body';

    /**
     * Забирает текст статьи из RSS источника, когда её страница закрыта
     * антибот-проверкой.
     *
     * Возвращает синтезированный HTML (заголовок + тело в контейнере с
     * известным классом) либо null, если фида нет, статьи в нём нет или
     * контент урезан. null означает «не помогло» — вызывающий код
     * продолжает обычную обработку сбоя.
     */
    private function fetchArticleFromFeed(string $articleUrl): ?string
    {
        $feedUrl = FeedArticleLocator::feedUrlFor($articleUrl);

        if ($feedUrl === null) {
            return null;
        }

        Log::info('StorePostJob: пробуем RSS источника', ['feed' => $feedUrl]);

        try {
            $xml = $this->fetchViaCurl($feedUrl);
        } catch (TransientFetchException $exception) {
            // Фид тоже под проверкой — обходного пути нет.
            Log::warning('StorePostJob: RSS недоступен', ['feed' => $feedUrl, 'reason' => $exception->getMessage()]);

            return null;
        }

        if (! is_string($xml) || $xml === '') {
            return null;
        }

        $article = FeedArticleLocator::findArticle($xml, $articleUrl);

        if ($article === null) {
            // Обычная причина: статья старше десяти последних публикаций,
            // а больше в фид не попадает.
            Log::warning('StorePostJob: статьи нет в RSS', ['feed' => $feedUrl, 'url' => $articleUrl]);

            return null;
        }

        if (FeedArticleLocator::isTruncated($article['html'])) {
            // Платная member-only статья: в фиде только вступление.
            // Публиковать огрызок хуже, чем честно пометить сбой.
            Log::warning('StorePostJob: в RSS только урезанный текст', ['url' => $articleUrl]);
            $this->fetchError = 'Статья платная: в RSS источника только вступление';

            return null;
        }

        // Дата из фида надёжнее той, что нашлась бы в синтезированном DOM.
        if ($article['published_at'] !== null) {
            try {
                $date = Carbon::parse($article['published_at']);

                // То же окно правдоподобия, что и у applyPublishedDate():
                // pubDate тоже приходит из чужого фида, а по created_at
                // сортируются все листинги, RSS и «популярное». Без проверки
                // источник ставил себе 2099 год и висел первым вечно.
                if (! $date->isFuture() && ! $date->lt(now()->subYears(5))) {
                    $this->data['created_at'] = $date->toDateTimeString();
                }
            } catch (\Throwable) {
                // Некорректный pubDate — не повод терять статью.
            }
        }

        // Селектор из дайджеста рассчитан на вёрстку страницы источника и к
        // нашему контейнеру не подойдёт — подменяем на свой.
        $this->data['selector'] = self::FEED_CONTENT_CLASS;

        Log::info('StorePostJob: статья взята из RSS', [
            'url' => $articleUrl,
            'length' => strlen($article['html']),
        ]);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($article['title']).'</title></head>'
            .'<body><h1>'.e($article['title']).'</h1>'
            .'<div class="'.self::FEED_CONTENT_CLASS.'">'.$article['html'].'</div>'
            .'</body></html>';
    }

    /**
     * Лежит ли уже разрешённый (realpath) путь внутри каталога импорта
     * config('releases.html_import_dir') — security-audit 2026-08-01, INF-1.
     *
     * Сверяем realpath обоих концов, а не исходные строки: сравнение строк
     * обходится и через `imports/../../../etc/passwd`, и через симлинк,
     * положенный внутрь каталога импорта.
     */
    private function isInsideHtmlImportDir(string $realPath): bool
    {
        $baseDir = realpath((string) config('releases.html_import_dir'));

        if ($baseDir === false) {
            return false;
        }

        return $realPath === $baseDir || str_starts_with($realPath, $baseDir.DIRECTORY_SEPARATOR);
    }

    /**
     * Скачивает HTML статьи: из локального файла (если передан html_file,
     * см. --html-file у post:parse), либо через curl/curl-impersonate
     * (curl-impersonate — если задан config('releases.curl_binary'),
     * чтобы обойти TLS-фингерпринтинг антибот-защит).
     *
     * URL статьи приходит из ссылок на странице релиза (чужого дайджеста),
     * поэтому автоследование редиректов (-L) отключено — каждый хоп
     * ревалидируется через UrlSafetyChecker, иначе 3xx со страницы-источника
     * может увести fetch на localhost/внутреннюю сеть/метаданные облака (SSRF).
     */
    private function fetchHtml(): string|false
    {
        if (! empty($this->data['html_file'])) {
            $path = realpath((string) $this->data['html_file']);

            // Несуществующий/нечитаемый файл — обычный постоянный сбой, и
            // сообщение о нём отличается от отказа по каталогу: иначе опечатка
            // в пути выглядела бы как срабатывание защиты.
            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                $this->fetchError = 'Не удалось прочитать файл '.$this->data['html_file'];

                return false;
            }

            if (! $this->isInsideHtmlImportDir($path)) {
                Log::warning('StorePostJob: html_file outside import dir', [
                    'file' => $path,
                    'import_dir' => config('releases.html_import_dir'),
                ]);
                $this->fetchError = 'Файл вне разрешённого каталога импорта ('.config('releases.html_import_dir').')';

                return false;
            }

            Log::debug('StorePostJob: reading from file', ['file' => $path]);

            $contents = @file_get_contents($path);

            if ($contents === false) {
                $this->fetchError = 'Не удалось прочитать файл '.$this->data['html_file'];
            }

            return $contents;
        }

        try {
            $html = $this->fetchViaCurl($this->data['url']);
        } catch (TransientFetchException $exception) {
            $bypassed = $this->fetchAroundChallenge($this->data['url']);

            if ($bypassed !== null) {
                return $bypassed;
            }

            throw $exception;
        }

        // Заглушка может приехать и с кодом 200 — тогда исключения не было,
        // но статьи в ответе всё равно нет.
        if (is_string($html) && $this->isChallengePage($html)) {
            $bypassed = $this->fetchAroundChallenge($this->data['url']);

            if ($bypassed !== null) {
                return $bypassed;
            }
        }

        return $html;
    }

    /**
     * Обходные пути, когда страница статьи закрыта antibot-проверкой.
     *
     * Порядок не случаен: RSS — один обычный запрос, а решение challenge
     * поднимает браузерную сессию секунд на двадцать. Сперва дешёвое.
     *
     * RSS при этом покрывает только последние десять публикаций автора,
     * поэтому для статей старше остаётся только headless-браузер — если он
     * включён (FLARESOLVERR_URL).
     */
    private function fetchAroundChallenge(string $url): ?string
    {
        $fromFeed = $this->fetchArticleFromFeed($url);

        if ($fromFeed !== null) {
            return $fromFeed;
        }

        return $this->fetchViaChallengeSolver($url);
    }

    /**
     * Забирает страницу через headless-браузер (FlareSolverr).
     *
     * Возвращает HTML целиком, как его увидел браузер, — дальше он идёт
     * обычным путём через selector, поэтому подменять data['selector'] тут
     * не нужно, в отличие от RSS-ветки.
     */
    private function fetchViaChallengeSolver(string $url): ?string
    {
        $solver = app(ChallengeSolverClient::class);

        if (! $solver->isEnabled()) {
            return null;
        }

        Log::info('StorePostJob: пробуем headless-браузер', ['url' => $url]);

        $html = $solver->solve($url);

        if ($html === null) {
            return null;
        }

        // Challenge пройден, но под ним может оказаться что угодно —
        // у medium.com так вскрылись удалённые статьи, которые Cloudflare
        // маскировал своим 403.
        if ($this->isChallengePage($html)) {
            Log::warning('StorePostJob: headless-браузер тоже упёрся в заглушку', ['url' => $url]);

            return null;
        }

        return $html;
    }

    /**
     * Скачивает URL через curl/curl-impersonate с ревалидацией каждого
     * редиректа и IP-пиннингом. Вынесено из fetchHtml(), чтобы тем же
     * защищённым путём ходить и за RSS-фидом, а не заводить рядом
     * четвёртую копию SSRF-логики.
     */
    private function fetchViaCurl(string $url): string|false
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->urlSafetyChecker->resolveTarget($url);
            if ($target === null) {
                Log::warning('StorePostJob: unsafe URL blocked', ['url' => $url, 'original_url' => $this->data['url']]);
                $this->fetchError = "Ссылка заблокирована проверкой безопасности: {$url} (недоступный DNS либо приватный адрес)";

                return false;
            }

            $url = $target->url;
            $result = $this->curlFetchOnce($target);

            // Соединение ушло не на тот адрес, который прошёл проверку —
            // значит пиннинг не сработал (например, --resolve не смэтчился
            // и curl сделал собственный резолв), и ответ пришёл неизвестно
            // откуда: доверять ему нельзя.
            if ($result['remote_ip'] !== null && ! $target->ipMatches($result['remote_ip'])) {
                Log::warning('StorePostJob: connection IP does not match pinned IP', [
                    'url' => $url,
                    'pinned_ip' => $target->ip,
                    'remote_ip' => $result['remote_ip'],
                ]);
                $this->fetchError = "Соединение ушло на непроверенный адрес {$result['remote_ip']} вместо {$target->ip}: {$url}";

                return false;
            }

            if ($result['location'] !== null) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($result['location']));

                continue;
            }

            if ($result['status'] === null) {
                // Сеть моргнула или источник не уложился в --max-time: ровно
                // тот случай, ради которого у джобы есть $tries и $backoff.
                throw new TransientFetchException(
                    "Источник не ответил: {$url} (таймаут, обрыв соединения либо превышен лимит размера ответа)"
                );
            }

            if ($result['status'] >= 500 || $result['status'] === 429) {
                // Проблема на стороне источника (или он просит притормозить) —
                // через минуту-другую та же ссылка обычно отдаётся нормально.
                throw new TransientFetchException("Источник вернул HTTP {$result['status']}: {$url}");
            }

            if ($result['status'] >= 400) {
                // Отдельный случай: 403 с телом антибот-заглушки — это не
                // «доступа нет», а «проверьте браузер». Наружу уходит
                // транзиентной ошибкой, но перехватывает её fetchHtml() и
                // сперва пробует RSS источника (см. fetchArticleFromFeed).
                //
                // Ретрай остаётся смыслом для площадок, где проверка
                // срабатывает от случая к случаю. Для medium.com он уже
                // бесполезен: страницы статей отдают 403 поголовно, включая
                // те, что раньше разбирались, — спасает только фид.
                if ($this->isChallengePage((string) $result['body'])) {
                    throw new TransientFetchException(
                        "Источник вернул HTTP {$result['status']} с антибот-заглушкой (Cloudflare): {$url}"
                    );
                }

                // 404/410/401 и «настоящий» 403 — повторять бессмысленно,
                // статьи там нет или доступ к ней закрыт. Фиксируем на посте.
                $this->fetchError = "Источник вернул HTTP {$result['status']}: {$url}";

                return false;
            }

            return $result['body'];
        }

        Log::warning('StorePostJob: too many redirects', ['url' => $this->data['url']]);
        $this->fetchError = 'Слишком много редиректов (>'.self::MAX_REDIRECTS.')';

        return false;
    }

    /**
     * Выполняет один HTTP-запрос без автоследования редиректов: заголовки
     * ответа пишутся в отдельный временный файл (-D), тело — в другой
     * (--output), чтобы достать статус/Location без "-L".
     *
     * IP цели закрепляется через --resolve вместо того, чтобы curl
     * резолвил хост самостоятельно — иначе между проверкой в
     * UrlSafetyChecker и самим запросом DNS-запись могла бы смениться на
     * приватный адрес (rebinding/TOCTOU). Сам пиннинг молчалив: если запись
     * --resolve не совпадёт с хостом (расхождение нормализации между
     * parse_url и libcurl), curl просто сделает свой резолв — поэтому
     * фактический адрес соединения возвращается через -w '%{remote_ip}'
     * и сверяется вызывающим кодом.
     *
     * @return array{body: string|false, location: ?string, status: ?int, remote_ip: ?string}
     */
    private function curlFetchOnce(PinnedTarget $target): array
    {
        $tmpBody = tempnam(sys_get_temp_dir(), 'curl_body_');
        $tmpHeaders = tempnam(sys_get_temp_dir(), 'curl_headers_');

        $url = $target->url;
        $escapedUrl = escapeshellarg($url);
        $resolveEntry = $target->resolveEntry();
        $resolve = $resolveEntry !== null ? '--resolve '.escapeshellarg($resolveEntry).' ' : '';
        // Тело уходит в --output, заголовки в -D, поэтому stdout свободен
        // под адрес соединения
        $writeOut = "-w '%{remote_ip}' ";
        $proxy = config('releases.curl_proxy') ? '--socks5 '.escapeshellarg(config('releases.curl_proxy')).' ' : '';
        $impersonate = config('releases.curl_binary');
        $proto = "--proto '=http,https' --proto-redir '=http,https' ";
        // Ни --max-time, ни сам факт allowed_domains не ограничивают ОБЪЁМ
        // ответа — страница может отдавать гигабайты/бесконечный поток и
        // положить воркер по памяти/диску.
        $maxFilesize = '--max-filesize '.((int) config('releases.max_content_length')).' ';

        if ($impersonate) {
            // curl-impersonate сам выставляет TLS-отпечаток и заголовки Chrome —
            // свои не добавляем, чтобы не выдать себя дублями заголовков
            $command = escapeshellcmd($impersonate).' -s --max-time 30 '.
                $proto.$resolve.$proxy.$maxFilesize.$writeOut.
                '-D '.escapeshellarg($tmpHeaders).' --output '.escapeshellarg($tmpBody).' '.
                "{$escapedUrl} 2>/dev/null";
        } else {
            $command = '/usr/bin/curl -s --max-time 30 --http2 '.
                $proto.$resolve.$proxy.$maxFilesize.$writeOut.
                "-H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' ".
                "-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ".
                "-H 'Accept-Language: en-US,en;q=0.9' ".
                '-D '.escapeshellarg($tmpHeaders).' --output '.escapeshellarg($tmpBody).' '.
                "{$escapedUrl} 2>/dev/null";
        }

        $remoteIp = trim((string) shell_exec($command));

        $body = file_get_contents($tmpBody);
        $headers = (string) file_get_contents($tmpHeaders);
        unlink($tmpBody);
        unlink($tmpHeaders);

        return [
            'body' => $body,
            'location' => $this->extractRedirectLocation($headers),
            'status' => $this->extractStatus($headers),
            // Пусто, если соединение не состоялось вовсе (таймаут, DNS, TLS).
            // Через SOCKS5-прокси сверять нечего: curl соединяется с прокси, и
            // %{remote_ip} показывает его адрес, а не адрес цели. Пиннинг при
            // этом продолжает работать — --socks5 (в отличие от --socks5h)
            // резолвит хост локально, то есть через запись --resolve.
            'remote_ip' => $proxy === '' && $remoteIp !== '' ? $remoteIp : null,
        ];
    }

    /**
     * Достаёт Location из последнего HTTP-ответа в заголовках curl
     * (при проксировании/keep-alive заголовки могут содержать несколько
     * блоков "HTTP/..." — берём последний) для статусов 3xx.
     */
    private function extractRedirectLocation(string $headers): ?string
    {
        $status = $this->extractStatus($headers);

        if ($status === null || $status < 300 || $status >= 400) {
            return null;
        }

        if (! preg_match('/^Location:\s*(\S+)/mi', $this->lastHeaderBlock($headers), $locationMatch)) {
            return null;
        }

        return trim($locationMatch[1]);
    }

    /**
     * HTTP-статус последнего ответа. null означает, что заголовков нет
     * вообще — то есть запрос не состоялся (таймаут, обрыв, ошибка TLS),
     * и это отдельная от "4xx/5xx" причина сбоя в parse_error.
     */
    private function extractStatus(string $headers): ?int
    {
        if (! preg_match('/^HTTP\/\S+\s+(\d{3})/', $this->lastHeaderBlock($headers), $statusMatch)) {
            return null;
        }

        return (int) $statusMatch[1];
    }

    private function lastHeaderBlock(string $headers): string
    {
        $blocks = preg_split('/\r?\n\r?\n(?=HTTP\/)/', trim($headers));
        $lastBlock = end($blocks);

        return $lastBlock === false ? '' : $lastBlock;
    }

    /**
     * Извлекает OG-изображение статьи (meta og:image) и скачивает его,
     * если превью ещё не задано другим способом.
     */
    private function applyOgImage(DOMDocument $dom): void
    {
        if (! empty($this->data['preview_image'])) {
            return;
        }

        $finder = new DOMXPath($dom);
        $ogImage = $finder->query("//meta[@property='og:image']/@content");
        if ($ogImage->length === 0) {
            return;
        }

        $ogImageUrl = $ogImage->item(0)->nodeValue;
        $imagePath = $this->imageService->downloadImage($ogImageUrl);
        if ($imagePath) {
            $this->data['preview_image'] = $imagePath;
            $this->data['main_image'] = $imagePath;
        }
    }

    /**
     * created_at поста должен отражать, когда статья вышла у источника,
     * а не когда её сюда затащил скрейпер (иначе дата на публичной
     * странице/RSS не соответствует реальной дате публикации оригинала).
     * Источник даты ищется в порядке убывания надёжности: структурированный
     * JSON-LD, article:published_time, itemprop/name meta, <time datetime>.
     */
    private function applyPublishedDate(DOMDocument $dom): void
    {
        $raw = $this->extractPublishedDateRaw($dom);
        if ($raw === null) {
            return;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('StorePostJob: failed to parse published date', ['raw' => $raw, 'error' => $e->getMessage()]);

            return;
        }

        // Защита от мусора в разметке источника (пустая/дефолтная дата,
        // будущее число) — в этом случае лучше оставить обычный now().
        // Окно доверия узкое намеренно: created_at берётся из JSON-LD и мета-тегов
        // чужой страницы, то есть полностью подконтролен источнику, а по нему
        // сортируются все листинги, RSS и «популярное». Границей 2000 год
        // источник мог выставить себе дату в далёком прошлом (статья уезжает
        // в конец всех выдач) или свежую (вечно висит первой). Всё, что вне
        // окна, откатывается на дату парсинга.
        if ($date->isFuture() || $date->lt(now()->subYears(5))) {
            Log::warning('StorePostJob: implausible published date, ignoring', ['raw' => $raw]);

            return;
        }

        $this->data['created_at'] = $date->toDateTimeString();
    }

    private function extractPublishedDateRaw(DOMDocument $dom): ?string
    {
        $finder = new DOMXPath($dom);

        return $this->findJsonLdDatePublished($dom)
            ?? $this->firstAttributeValue($finder, "//meta[@property='article:published_time']/@content")
            ?? $this->firstAttributeValue($finder, "//meta[@itemprop='datePublished']/@content")
            ?? $this->firstAttributeValue($finder, "//meta[@name='date']/@content")
            ?? $this->firstAttributeValue($finder, '//time[@datetime]/@datetime');
    }

    private function firstAttributeValue(DOMXPath $finder, string $query): ?string
    {
        $nodes = $finder->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)->nodeValue);

        return $value !== '' ? $value : null;
    }

    /**
     * datePublished из JSON-LD (<script type="application/ld+json">) —
     * структурированные данные (Article/BlogPosting), которые сайты обычно
     * пишут для Google и которые надёжнее человекочитаемой разметки.
     * Формат — либо один объект, либо массив объектов, либо {"@graph": [...]}.
     */
    private function findJsonLdDatePublished(DOMDocument $dom): ?string
    {
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }

            $json = json_decode($script->nodeValue, true);
            if (! is_array($json)) {
                continue;
            }

            $nodes = array_is_list($json) ? $json : [$json];

            foreach ($nodes as $node) {
                $date = $this->datePublishedFromJsonLdNode($node);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function datePublishedFromJsonLdNode(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        if (isset($node['datePublished']) && is_string($node['datePublished'])) {
            return $node['datePublished'];
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $graphNode) {
                $date = $this->datePublishedFromJsonLdNode($graphNode);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Возвращает список <h1> статьи. Если на странице их нет, строит
     * заглушку из <title> (обрезая суффикс вида " | Site Name"), чтобы
     * дальнейшая экстракция заголовка не осталась совсем без кандидата.
     */
    private function resolveH1(DOMDocument $dom): DOMNodeList
    {
        $h1 = $dom->getElementsByTagName('h1');
        if ($h1->length > 0) {
            return $h1;
        }

        $titleTag = $dom->getElementsByTagName('title');
        if ($titleTag->length === 0) {
            return $h1;
        }

        $rawTitle = $titleTag->item(0)->nodeValue;
        // Strip site name suffix (e.g. "Article Title | Site Name")
        $rawTitle = preg_replace('/\s*[|\-—]\s*[^|\-—]+$/', '', $rawTitle);
        $fakeH1 = $dom->createElement('h1', htmlspecialchars(trim($rawTitle)));
        $dom->documentElement->appendChild($fakeH1);

        return $dom->getElementsByTagName('h1');
    }

    /**
     * Заполняет заголовок и контент поста из DOM статьи: выбирает
     * заголовок среди кандидатов <h1>, чистит интерфейсный мусор, переводит
     * содержимое по CSS/ID-селектору и только потом — заголовок и обложку.
     *
     * Порядок именно такой, а не обратный, и это не косметика. Заголовок
     * переводится ПОСЛЕ того, как контент извлёкся: страница, которая не
     * отдала тела статьи, всё равно станет заглушкой, а перевод её заголовка
     * — это оплаченный запрос к модели за результат, который никто не увидит.
     * Пока новостной импорт ходил по расписанию, он каждое утро перезапускал
     * десяток таких заглушек (ролики YouTube, главная php.net), и 24.08.2026
     * ВСЕ 11 вызовов модели за сутки пришлись на них — 55% бесплатной квоты.
     *
     * Заглушка при этом не остаётся безымянной: сырой заголовок кладётся в
     * data['title'] сразу, просто по-английски. Для непереведённой ссылки в
     * админке этого достаточно, а проверка «на странице не найден заголовок»
     * (см. handle) продолжает работать как раньше.
     */
    private function extractArticle(DOMDocument $dom, DOMNodeList $h1): void
    {
        $this->data['title'] = $this->selectArticleTitleNode($h1)->nodeValue;

        // Запоминаем ДО извлечения контента, была ли у статьи своя обложка.
        // Ниже translateContentNodes() при отсутствии preview_image берёт
        // первую картинку из тела статьи — и если спросить об обложке после
        // него, под OCR попадёт внутренняя иллюстрация. Режим headingOnly
        // рассчитан на обложку площадки, а на диаграмме или скриншоте он
        // закрашивает самый крупный текстовый блок и рисует поверх перевод.
        // Файл переписывается на месте, бэкапа нет — потеря безвозвратна.
        $hasOwnCover = filled($this->data['preview_image'] ?? null);

        // Категория определяется позже, в PostService::store()/update() —
        // там уже доступен полный текст статьи (см. CategoryDetectorService),
        // а на этом этапе контент ещё не извлечён из DOM
        Log::debug('StorePostJob: extracting content', ['title' => $this->data['title'], 'category_id' => $this->data['category_id'] ?? null]);

        $finder = new DOMXPath($dom);

        $this->stripKnownJunk($finder);

        // Пустой селектор разрешаем сами, а не доверяем вызывающему:
        // SelectorXPathBuilder::build('') даёт запрос, матчащий всю страницу,
        // и в статью молча попадали навигация сайта, сайдбар и подвал
        // (у laravel-news.com это 113 ссылок и 48 картинок вместо 26 и 5).
        // Резолвер тот же, что у post:parse — правила из админки и конфига.
        $selector = filled($this->data['selector'] ?? null)
            ? $this->data['selector']
            : app(ContentSelectorResolver::class)->resolve((string) ($this->data['url'] ?? ''));

        $xpathQuery = SelectorXPathBuilder::build($selector);
        $nodes = $finder->query($xpathQuery);

        Log::debug('selector nodes found', ['selector' => $selector, 'xpath' => $xpathQuery, 'count' => $nodes === false ? 0 : $nodes->count()]);

        // Селектор ничего не нашёл — тела статьи на странице нет. Выходим до
        // обращения к модели и до OCR: заглушке ни то ни другое не нужно, а
        // стоят они запроса к внешнему API и секунд процессорного времени.
        if ($nodes === false || $nodes->count() === 0) {
            return;
        }

        $this->translateContentNodes($dom, $nodes);

        // Узлы нашлись, но текста в них не оказалось — тот же случай.
        if (empty($this->data['content'])) {
            return;
        }

        $titleResult = $this->translator->translateText($this->data['title']);
        $this->data['title'] = $titleResult->text;

        if ($titleResult->failed) {
            $this->translationFallbacks++;
        }

        if ($hasOwnCover) {
            $this->translateCoverImage();
        }
    }

    /**
     * Переводит текст, распознанный OCR прямо на уже скачанной обложке
     * статьи (если она есть) — не зависит от найденного заголовка/
     * расположения фона, в отличие от прежнего ImageTranslatorService.
     */
    private function translateCoverImage(): void
    {
        if (empty($this->data['preview_image'])) {
            return;
        }

        try {
            $fullPath = Storage::disk('public')->path($this->data['preview_image']);
            // Только заголовок: остальное на обложке — подпись автора, дата и
            // логотипы площадки. Их перевод портит картинку, а закраска строки,
            // в которую Tesseract сваливает всю нижнюю плашку, стирает заодно и
            // логотип (см. докблок DiagramTranslatorService::translate).
            $this->diagramTranslator->translate($fullPath, headingOnly: true);
        } catch (\Throwable $e) {
            Log::warning('DiagramTranslatorService: cover image translate failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Убирает панель действий Dev.to и известный интерфейсный мусор
     * (см. stripAccessibilityJunk/stripMediumSubscribeWidget/stripDuplicateTitleAndHero)
     * перед выбором контентных узлов по селектору.
     */
    private function stripKnownJunk(DOMXPath $finder): void
    {
        $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

        foreach ($panels as $panel) {
            $panel->parentNode->removeChild($panel);
        }

        $this->stripAccessibilityJunk($finder);
        $this->stripMediumSubscribeWidget($finder);
        $this->stripJetBrainsChrome($finder);
        $this->stripDuplicateTitleAndHero($finder);
    }

    /**
     * Убирает обвязку блога JetBrains из тела статьи. В div.js-toc-content
     * (единственный контейнер, который вообще оборачивает текст статьи)
     * лежит не только сам текст: сверху — ссылки на категории и шапка автора
     * (аватар, имя, дата), снизу — список тегов с кнопками шаринга,
     * пагинация «Prev/Next post» и форма подписки на рассылку. Всё это
     * интерфейс, а не контент, и в переводе выглядит мусором.
     */
    private function stripJetBrainsChrome(DOMXPath $finder): void
    {
        $junkClasses = [
            'author-post',        // аватар + имя автора + дата публикации
            'content__row',       // теги статьи + кнопки «Поделиться»
            'content__pagination', // ссылки на предыдущий/следующий пост
            'content__form',      // форма подписки на рассылку
        ];

        foreach ($junkClasses as $class) {
            $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");

            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Ссылки на разделы блога (<a class="tag">Community</a>) идут прямо
        // перед заголовком статьи, вне какой-либо обёртки — удаляем точечно.
        $tags = $finder->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' tag ') and contains(@href, '/category/')]");

        foreach (iterator_to_array($tags) as $tag) {
            $tag->parentNode?->removeChild($tag);
        }
    }

    /**
     * Сохраняет оригинальный HTML найденных узлов, переводит их через общий
     * слой перевода и записывает итоговый контент в $this->data, с фолбэком
     * превью-картинки из первой локальной картинки контента, если своей ещё
     * нет.
     */
    private function translateContentNodes(DOMDocument $dom, DOMNodeList $nodes): void
    {
        $contentOrig = '';
        foreach ($nodes as $node) {
            $contentOrig .= $dom->saveHTML($node);
        }

        // Через общий слой перевода, а не поузловым обходом: движок может
        // оказаться и LLM, и прежним скрейпером, и джобе незачем знать, каким
        // именно. Раньше здесь жёстко звался processNode(), поэтому весь
        // парсинг шёл мимо настроек перевода вообще.
        $contentOrig = $this->imageService->absolutizeImageUrls($contentOrig, (string) ($this->data['url'] ?? ''));

        $result = $this->translator->translateHtml($contentOrig);

        if ($result->failed || $result->partial) {
            $this->translationFallbacks++;
        }

        $postContent = $this->modifyContent($result->text);

        $postContent = $this->imageService->replacePictureElements($postContent);
        $postContent = $this->imageService->downloadAndReplaceImages($postContent);

        // Движок записываем по РЕЗУЛЬТАТУ, а не по тому, кто взялся за работу.
        // 24.08.2026 у поста 236 стояло translated_by = 'google' при нулевом
        // числе кириллических символов: скрейпер получил 429 на каждом блоке,
        // вернул все блоки как есть и отчитался частичным успехом. Признак
        // «переведено гуглом» на непереведённой статье — не мелкая неточность
        // отчёта, по нему выбирают, что переводить заново.
        //
        // Сравниваем с исходным текстом, а не ищем кириллицу: статья, у
        // которой вся проза в заголовке, а тело — сплошной <pre>, переводится
        // корректно и кириллицы в теле не получает. Совпадение с оригиналом
        // же означает буквально «ничего не изменилось».
        if ($result->text !== $contentOrig) {
            $this->data['translated_by'] = $result->engine;
        } else {
            $this->data['translated_by'] = TranslationResult::NO_ENGINE;
            $this->translationFallbacks++;
        }

        if (empty($postContent)) {
            return;
        }

        $this->data['content'] = $postContent;
        $this->data['content_orig'] = $contentOrig;

        if (empty($this->data['preview_image'])) {
            $imagePath = $this->extractFirstImagePath($postContent);
            if ($imagePath) {
                $this->data['preview_image'] = $imagePath;
                $this->data['main_image'] = $imagePath;
            }
        }
    }

    /**
     * Убирает элементы с классом "speechify-ignore" — общий маркер
     * инструментов доступности ("это не текст статьи, не читать вслух"),
     * используется не только на Medium. Захватывает шапку автора
     * (аватар/имя/время чтения/дата/кнопки Слушать-Поделиться) и подсказки
     * вида "нажмите Enter, чтобы открыть картинку в полный размер" —
     * мусор интерфейса, не контент статьи.
     */
    private function stripAccessibilityJunk(DOMXPath $finder): void
    {
        $junk = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' speechify-ignore ')]");

        foreach (iterator_to_array($junk) as $junkNode) {
            $junkNode->parentNode?->removeChild($junkNode);
        }
    }

    /**
     * Убирает встроенный виджет Medium «Get stories from X in your inbox»
     * (форма email, кнопки подписки, чекбокс «запомнить меня», recaptcha).
     * Medium вставляет его прямо посреди текста статьи (не в конце), из-за
     * чего в переводе появляется дублирующийся текст вида «Подписаться
     * Подписаться» и обрывок формы входа. Классы у Medium хэшированные и
     * меняются от сборки к сборке, поэтому ищем по стабильным маркерам —
     * полю email и блоку recaptcha — и удаляем их ближайшего общего предка.
     */
    private function stripMediumSubscribeWidget(DOMXPath $finder): void
    {
        $emailInputs = $finder->query("//input[@placeholder='Enter your email']");

        foreach (iterator_to_array($emailInputs) as $input) {
            $ancestor = $input->parentNode;

            while ($ancestor && $finder->query(".//*[@id='g-recaptcha']", $ancestor)->length === 0) {
                $ancestor = $ancestor->parentNode;
            }

            if ($ancestor instanceof DOMElement && $ancestor->parentNode) {
                $ancestor->parentNode->removeChild($ancestor);
            }
        }
    }

    /**
     * Убирает дублирующийся заголовок и обложку статьи из тела контента.
     * Страница поста уже рисует $post->title и $post->main_image своим
     * собственным <h1> и картинкой в шапке — если экстрактор (например,
     * <article> целиком на Medium/Stackademic) захватывает ещё и заголовок
     * статьи (<h1 data-testid="storyTitle">) с её обложкой (<figure><img>
     * сразу после заголовка), они дублируются на странице поста.
     */
    private function stripDuplicateTitleAndHero(DOMXPath $finder): void
    {
        foreach (iterator_to_array($finder->query("//h1[@data-testid='storyTitle']")) as $titleNode) {
            $figure = $finder->query('following::figure[1]', $titleNode)->item(0);
            if ($figure instanceof DOMElement && $figure->parentNode) {
                $figure->parentNode->removeChild($figure);
            }
        }

        // Любой оставшийся <h1> внутри тела статьи всегда дублирует
        // заголовок страницы — подзаголовки в контенте должны быть h2+.
        foreach (iterator_to_array($finder->query('//h1')) as $h1) {
            $h1->parentNode?->removeChild($h1);
        }
    }

    /**
     * Определяет антибот-заглушку (Cloudflare и т.п.) вместо реальной статьи.
     * Без этой проверки заглушка «Just a moment...» проходила как валидный
     * title и создавался мусорный пост с пустым контентом.
     */
    private function isChallengePage(string $html): bool
    {
        // Внимание: маркер '/cdn-cgi/challenge-platform/' НЕ подходит —
        // Cloudflare инжектит этот скрипт и в легитимные страницы (скоринг),
        // получается ложное срабатывание на нормальных статьях
        $markers = [
            '<title>Just a moment...</title>',
            'cf-browser-verification',
            'Attention Required! | Cloudflare',
        ];

        foreach ($markers as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Выбирает h1, соответствующий заголовку статьи, а не логотипу сайта.
     *
     * Частый паттерн вёрстки: <h1><a href="/">SiteName</a></h1> в шапке
     * идёт раньше настоящего h1 статьи в DOM-порядке — брать item(0) без
     * разбора значит получить название сайта вместо заголовка (см. баг
     * с "шитье.io" на stitcher.io — Google дословно перевёл имя сайта).
     */
    private function selectArticleTitleNode(DOMNodeList $h1Nodes): DOMElement
    {
        foreach ($h1Nodes as $node) {
            if (! $this->looksLikeSiteLogoHeading($node)) {
                return $node;
            }
        }

        return $h1Nodes->item(0);
    }

    private function looksLikeSiteLogoHeading(DOMElement $h1): bool
    {
        $links = $h1->getElementsByTagName('a');
        if ($links->length !== 1) {
            return false;
        }

        $href = trim($links->item(0)->getAttribute('href'));
        if (! in_array($href, ['/', '', '#'], true)) {
            return false;
        }

        return trim($h1->textContent) === trim($links->item(0)->textContent);
    }

    /**
     * Извлекает путь первой локально сохранённой картинки из контента.
     * Возвращает путь относительно storage/public (например images/content/xxx.jpg).
     */
    private function extractFirstImagePath(string $content): ?string
    {
        if (! preg_match('/<img[^>]+src="([^"]+)"/i', $content, $matches)) {
            return null;
        }

        $url = $matches[1];
        $storageUrl = rtrim(Storage::disk('public')->url(''), '/').'/';

        if (! str_starts_with($url, $storageUrl)) {
            return null;
        }

        return str_replace($storageUrl, '', $url);
    }

    /**
     * Modifies the content of a post to make it suitable for display.
     *
     * Replaces certain tags with versions that have a non-breaking space added to the start and/or end.
     * This is needed because some tags (like <code>) don't wrap properly without the extra space.
     *
     * @param  string  $postContent  The content of the post to modify.
     * @return string The modified content.
     */
    private function modifyContent(string $postContent): string
    {
        return str_replace(
            // The tags we want to modify
            ['<code>', '</code>', '<strong>'],
            // The modified versions of the tags
            ['&nbsp;<code>', '</code>&nbsp;', '&nbsp;<strong>'],
            // The content to modify
            $postContent
        );
    }
}
