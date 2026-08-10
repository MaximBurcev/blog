<?php

namespace App\Service;

use App\Support\UrlSafetyChecker;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentImageService
{
    private const MAX_REDIRECTS = 5;

    /**
     * Каталог скачанных картинок на диске public. Вынесен в константу,
     * потому что по нему же второй проход downloadAndReplaceImages()
     * отличает уже локальную картинку от внешней.
     */
    private const CONTENT_IMAGE_DIR = 'images/content';

    /**
     * Сколько картинок максимум тянем из одной статьи.
     *
     * releases.max_content_length ограничивает ОДИН ответ (20 МБ), но не их
     * количество: страница с пятью сотнями <img> заставляла воркер выкачать
     * гигабайты в storage и породить столько же GenerateImageVariantsJob с
     * шестью перекодировками каждая. Сверх лимита картинка остаётся внешней
     * ссылкой — статья не ломается.
     */
    private const MAX_IMAGES_PER_ARTICLE = 40;

    /**
     * Счётчик скачанных картинок в пределах одного разбора статьи. Сервис
     * живёт ровно столько же (создаётся под джобу), поэтому состояние
     * инстанса тут уместно.
     */
    private int $downloadedCount = 0;

    public function __construct(
        private readonly UrlSafetyChecker $urlSafetyChecker = new UrlSafetyChecker,
        private readonly WebpConverterService $webpConverter = new WebpConverterService
    ) {}

    /**
     * Скачивает содержимое по URL, ревалидируя через UrlSafetyChecker
     * перед каждым хопом редиректа — картинки в скрейпленном контенте
     * ведут туда, куда их поставила чужая страница (SSRF-защита).
     * Возвращает null, если URL небезопасен, редиректов слишком много
     * или запрос не удался.
     */
    private function fetchBinary(string $url): ?string
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->urlSafetyChecker->resolveTarget($current);
            if ($target === null) {
                Log::warning('ContentImageService: unsafe URL blocked', ['url' => $current, 'original_url' => $url]);

                return null;
            }

            $current = $target->url;
            $connectedIp = null;

            // IP закрепляется через CURLOPT_RESOLVE вместо повторного резолва
            // хоста Guzzle'ом — иначе между проверкой и запросом DNS-запись
            // могла бы смениться на приватный адрес (rebinding/TOCTOU).
            // CURLOPT_MAXFILESIZE_LARGE — источник по своей воле отдаёт
            // ответ (og:image/src в чужом HTML), ничего не мешает вернуть
            // гигабайты и положить воркер по памяти/диску; сработает, если
            // сервер объявляет Content-Length заранее (обычный случай).
            $response = Http::timeout(30)->retry(3, 500)->withOptions([
                'allow_redirects' => false,
                'on_stats' => function (TransferStats $stats) use (&$connectedIp) {
                    $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                },
                'curl' => array_filter([
                    CURLOPT_RESOLVE => $target->resolveEntry() !== null ? [$target->resolveEntry()] : null,
                    CURLOPT_MAXFILESIZE_LARGE => (int) config('releases.max_content_length'),
                ]),
            ])->get($current);

            // CURLOPT_RESOLVE молчит, если запись не смэтчилась с хостом
            // (расхождение нормализации между parse_url и libcurl) — тогда
            // curl резолвит сам, и пиннинг превращается в фикцию. Сверяем
            // фактический адрес соединения с проверенным.
            if ($connectedIp !== null && ! $target->ipMatches($connectedIp)) {
                Log::warning('ContentImageService: connection IP does not match pinned IP', [
                    'url' => $current,
                    'pinned_ip' => $target->ip,
                    'remote_ip' => $connectedIp,
                ]);

                return null;
            }

            if ($response->redirect() && $response->header('Location')) {
                $current = (string) UriResolver::resolve(new Uri($current), new Uri($response->header('Location')));

                continue;
            }

            return $response->successful() ? $response->body() : null;
        }

        Log::warning('ContentImageService: too many redirects', ['url' => $url]);

        return null;
    }

    /**
     * Downloads images from external sources and replaces them with local paths.
     *
     * @param  string  $content
     */
    /**
     * Downloads a single image by URL and saves it to storage.
     * Returns the storage-relative path (e.g. images/content/xxx.jpg), or null on failure.
     */
    public function downloadImage(string $url): ?string
    {
        try {
            $imageContent = $this->fetchBinary($url);
            if ($imageContent === null) {
                return null;
            }

            $filename = $this->storeImage($imageContent, $url);

            Log::debug('ContentImageService: downloaded', ['url' => $url, 'path' => $filename]);

            return $filename;
        } catch (\Exception $e) {
            Log::warning('ContentImageService: download failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Заменяет <picture>...</picture> с ленивой загрузкой (source srcset,
     * сам <img> без src — паттерн Medium/gitconnected для картинок в теле
     * статьи) на обычный <img src="local-path">. Без этого
     * downloadAndReplaceImages() такие картинки вообще не находит — его
     * регексп требует уже существующий src у <img>, а тут его нет,
     * реальный URL только в srcset.
     */
    public function replacePictureElements(string $content): string
    {
        $pattern = '/<picture>(.*?)<\/picture>/is';

        return preg_replace_callback($pattern, function ($matches) {
            $pictureInner = $matches[1];
            $bestUrl = $this->extractBestSrcsetUrl($pictureInner);

            if (! $bestUrl || ! $this->mayDownloadMore()) {
                return $matches[0];
            }

            // Сохраняем ширину/высоту из исходного <img> внутри picture, чтобы не сломать вёрстку
            $sizeAttrs = '';
            if (preg_match('/<img[^>]+width="(\d+)"[^>]+height="(\d+)"/i', $pictureInner, $imgMatch)) {
                $sizeAttrs = ' width="'.$imgMatch[1].'" height="'.$imgMatch[2].'"';
            }

            try {
                $imageContent = $this->fetchBinary($bestUrl);
                if ($imageContent === null) {
                    return $matches[0];
                }

                $filename = $this->storeImage($imageContent, $bestUrl);
                $newImageUrl = Storage::disk('public')->url($filename);

                Log::debug('ContentImageService: picture replaced', ['url' => $bestUrl, 'path' => $filename]);

                return '<img src="'.$newImageUrl.'"'.$sizeAttrs.'>';
            } catch (\Exception $e) {
                Log::warning('ContentImageService: picture download failed', ['url' => $bestUrl, 'error' => $e->getMessage()]);

                return $matches[0];
            }
        }, $content);
    }

    /**
     * Не исчерпан ли лимит картинок на статью. Счётчик увеличивается здесь,
     * а не после успешного сохранения: ограничивать нужно число попыток
     * скачивания (это и есть внешний трафик), а не число удачных.
     */
    private function mayDownloadMore(): bool
    {
        if ($this->downloadedCount >= self::MAX_IMAGES_PER_ARTICLE) {
            Log::warning('ContentImageService: достигнут лимит картинок на статью, остальные останутся внешними ссылками', [
                'limit' => self::MAX_IMAGES_PER_ARTICLE,
            ]);

            return false;
        }

        $this->downloadedCount++;

        return true;
    }

    /**
     * Расширения, с которыми файл вообще может лечь в storage, и типы GD,
     * которым они соответствуют. Ключ — константа IMAGETYPE_*.
     */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_AVIF => 'avif',
        IMAGETYPE_BMP => 'bmp',
    ];

    /**
     * Кладёт скачанную картинку в storage и возвращает относительный путь.
     *
     * Растровые оригиналы по пути перекодируются в WebP: обложки статей
     * приезжают PNG'ами по 150-300 КБ, в WebP тот же кадр в разы легче.
     * Если конвертация не применима (GIF-анимация, битый файл) или не даёт
     * выигрыша — сохраняется оригинал.
     *
     * Расширение определяется по СОДЕРЖИМОМУ, а не по URL источника.
     * Раньше оно бралось из пути URL, и это была RCE: страница-источник
     * решает, что написать в src, конвертер возвращает null для всего, что
     * не картинка, и `<img src="https://evil.tld/x.php">` укладывал чужие
     * байты в storage/app/public/images/content/<rand>.php — то есть в
     * каталог, который Apache отдаёт напрямую по /storage и исполняет
     * (SetHandler для .ph(ar|p|tml) объявлен глобально). Тем же путём
     * заезжал .svg — активный контент с нашего origin, причём мимо
     * SecurityHeaders: на статику CSP не навешивается.
     *
     * SVG не принимаем сознательно, хотя это валидный формат картинки:
     * он умеет исполнять скрипт, а отдаётся с нашего домена. Такие
     * картинки останутся внешними ссылками — это хуже для приватности
     * читателя, но не даёт XSS.
     */
    private function storeImage(string $binary, string $sourceUrl): string
    {
        $webp = $this->webpConverter->convert($binary);

        if ($webp !== null) {
            $binary = $webp;
            $extension = 'webp';
        } else {
            $type = @getimagesizefromstring($binary)[2] ?? null;

            if (! isset(self::ALLOWED_IMAGE_TYPES[$type])) {
                throw new \RuntimeException(
                    'Скачанный файл не является растровым изображением: '.$sourceUrl
                );
            }

            $extension = self::ALLOWED_IMAGE_TYPES[$type];
        }

        $filename = self::CONTENT_IMAGE_DIR.'/'.Str::random(40).'.'.$extension;
        Storage::disk('public')->put($filename, $binary);

        return $filename;
    }

    /**
     * Ищет лучший (максимального разрешения) URL среди <source srcset="...">
     * внутри <picture>. Предпочитает source с data-testid="og" (обычно
     * не-webp, более совместимый формат оригинала), иначе берёт первый.
     */
    private function extractBestSrcsetUrl(string $pictureInner): ?string
    {
        if (! preg_match_all('/<source\b[^>]*srcset="([^"]+)"[^>]*>/i', $pictureInner, $sourceMatches, PREG_SET_ORDER)) {
            return null;
        }

        $ogSrcset = null;
        foreach ($sourceMatches as $sourceMatch) {
            if (str_contains($sourceMatch[0], 'data-testid="og"')) {
                $ogSrcset = $sourceMatch[1];
                break;
            }
        }

        $srcset = $ogSrcset ?? $sourceMatches[0][1];

        $best = null;
        $bestWidth = -1;
        if (preg_match_all('/([^\s,]+)\s+(\d+)w/', $srcset, $entries, PREG_SET_ORDER)) {
            foreach ($entries as $entry) {
                $width = (int) $entry[2];
                if ($width > $bestWidth) {
                    $bestWidth = $width;
                    $best = $entry[1];
                }
            }
        }

        return $best;
    }

    /**
     * Скачивает картинки статьи и подменяет их адреса на локальные.
     *
     * Два прохода, и порядок важен. Сначала разбираются картинки, обёрнутые
     * в ссылку (<a href><img></a>) — у них меняется и href, и src. Затем
     * одиночные <img>: dev.to и Medium (а в content:encoded из RSS — всегда)
     * отдают картинки без обёртки, и прежний единственный шаблон их
     * пропускал. Такие картинки оставались внешними: IP читателя утекал
     * в чужой CDN, а сама картинка пропадала, если её удаляли у источника.
     *
     * Второй проход пропускает уже локальные адреса, поэтому картинки,
     * обработанные первым проходом (и replacePictureElements до него),
     * не скачиваются повторно.
     */
    public function downloadAndReplaceImages(string $content): string
    {
        return $this->replaceBareImages($this->replaceLinkedImages($content));
    }

    private function replaceLinkedImages(string $content): string
    {
        $pattern = '/<a[^>]+href="([^"]+)"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>.*?<\/a>/i';

        return preg_replace_callback($pattern, function ($matches) {
            $imageUrl = $matches[2];

            if (! $this->isDownloadableImageUrl($imageUrl) || ! $this->mayDownloadMore()) {
                return $matches[0];
            }

            try {
                $newImageUrl = $this->downloadToPublicUrl($imageUrl);

                return $newImageUrl === null
                    ? $matches[0]
                    : '<a href="'.$newImageUrl.'"><img src="'.$newImageUrl.'"></a>';
            } catch (\Exception $e) {
                Log::warning('ContentImageService: link image download failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);

                return $matches[0];
            }
        }, $content);
    }

    private function replaceBareImages(string $content): string
    {
        $pattern = '/<img[^>]+src="([^"]+)"[^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) {
            $imageUrl = $matches[1];

            if (! $this->isDownloadableImageUrl($imageUrl) || ! $this->mayDownloadMore()) {
                return $matches[0];
            }

            try {
                $newImageUrl = $this->downloadToPublicUrl($imageUrl);

                if ($newImageUrl === null) {
                    return $matches[0];
                }

                // Подменяем только src, а не собираем тег заново: width и
                // height из исходного тега нужны вёрстке (см. srcset в
                // ImageVariantService), и терять их нельзя.
                return preg_replace('/src="[^"]*"/i', 'src="'.$newImageUrl.'"', $matches[0], 1) ?? $matches[0];
            } catch (\Exception $e) {
                Log::warning('ContentImageService: bare image download failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);

                return $matches[0];
            }
        }, $content);
    }

    /**
     * Стоит ли вообще пытаться скачать этот адрес: только http(s) и только
     * то, что ещё не лежит у нас (иначе второй проход перекачивал бы
     * результат первого).
     */
    private function isDownloadableImageUrl(string $url): bool
    {
        return str_starts_with($url, 'http') && ! $this->isLocalImage($url);
    }

    private function isLocalImage(string $url): bool
    {
        return str_contains($url, '/'.self::CONTENT_IMAGE_DIR.'/');
    }

    private function downloadToPublicUrl(string $imageUrl): ?string
    {
        $imageContent = $this->fetchBinary($imageUrl);

        if ($imageContent === null) {
            return null;
        }

        return Storage::disk('public')->url($this->storeImage($imageContent, $imageUrl));
    }
}
