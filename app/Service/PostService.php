<?php

namespace App\Service;

use App\DataTransferObjects\PostData;
use App\Events\PostCreated;
use App\Exceptions\PostPersistenceException;
use App\Models\Post;
use App\Support\PostCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PostService
{
    public function __construct(
        private readonly TranslateService $translateService,
        private readonly CategoryDetectorService $categoryDetectorService,
        private readonly TagDetectorService $tagDetectorService,
        private readonly LlmTaggerService $llmTaggerService,
    ) {}

    public function store(PostData $postData): Post
    {
        $data = $postData->toArray();

        Log::info('PostService::store', ['title' => $data['title'] ?? null, 'url' => $data['url'] ?? null]);

        $post = null;

        try {
            DB::beginTransaction();
            $tagIds = $data['tag_ids'] ?? [];
            unset($data['tag_ids'], $data['html_file']);

            // Введённый в форме slug уважаем: раньше строка ниже была
            // безусловной, и поле «Код (slug)» на создании поста не значило
            // ничего — что бы админ ни ввёл, код пересчитывался из заголовка.
            $data['code'] = filled($data['code'] ?? null)
                ? PostCode::normalize($data['code'])
                : PostCode::fromTitle($data['title']);
            $data['selector'] = '';
            $data['content'] = $data['content'] ?? '';

            if (array_key_exists('preview_image', $data) && $data['preview_image'] instanceof UploadedFile) {
                $data['preview_image'] = Storage::disk('public')->put('/images', $data['preview_image']);
            }

            if (array_key_exists('main_image', $data) && $data['main_image'] instanceof UploadedFile) {
                $data['main_image'] = Storage::disk('public')->put('/images', $data['main_image']);
            }

            if (($data['translate'] ?? null) == 'on') {
                $data = $this->translateService->translate($data);
                // url НЕ обнуляем: это естественный ключ дедупа скрейпленной
                // статьи (updateOrCreate ниже). Раньше обнуление уводило
                // переведённые посты в Post::create → дубль на каждый ре-парсинг.
            }

            if (empty($data['category_id'])) {
                $data['category_id'] = $this->categoryDetectorService->detect(
                    $data['title'],
                    $data['url'] ?? '',
                    $data['content'] ?? ''
                );
            }

            // Джоба скрейпинга не идемпотентна сама по себе (retry/redelivery
            // повторяет весь handle()) — при непустом url привязываемся к
            // нему как к естественному ключу, иначе retry создавал бы
            // дубликат поста при каждом повторном запуске.
            if (! empty($data['url'])) {
                $existing = Post::where('url', $data['url'])->first();
                $data = $this->keepExistingContent($data, $existing);
                $data = $this->keepExistingCode($data, $existing);
                $post = Post::updateOrCreate(['url' => $data['url']], $data);
            } else {
                $post = Post::create($data);
            }

            Log::info('PostService::store: post created', ['id' => $post->id, 'title' => $post->title]);

            if (empty($tagIds)) {
                $tagIds = $this->tagDetectorService->detect($data['title'], $data['url'] ?? '', $data['content'] ?? '');
            }

            // Словарь молчит — спрашиваем модель. Она платная и медленная,
            // поэтому именно в таком порядке, и только для безтеговых постов:
            // иначе статья уходила на сайт вообще без тегов.
            if (empty($tagIds)) {
                $tagIds = $this->llmTaggerService->detect($data['title'], $data['url'] ?? '', $data['content'] ?? '');
            }

            if (! empty($tagIds)) {
                // sync, а не attach: store() идемпотентен по url (updateOrCreate),
                // повторный StorePostJob для существующего поста с attach() плодил
                // дубли в post_tags. sync выставляет ровно нужный набор тегов.
                $post->tags()->sync($tagIds);
            }

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error('PostService::store failed', ['error' => $exception->getMessage()]);
            throw new PostPersistenceException('Не удалось сохранить пост', previous: $exception);
        }

        // Только для реально созданных постов: при ре-парсинге updateOrCreate
        // обновляет существующий пост — повторно рассылать уведомления не нужно.
        if ($post->wasRecentlyCreated) {
            PostCreated::dispatch($post);
        }

        return $post;
    }

    /**
     * @deprecated Правило переехало в App\Support\PostCode: им же пользуются
     * форма Filament и консольные команды, чтобы адрес не расходился с тем,
     * что показано администратору.
     */
    private function makeCode(?string $title): string
    {
        return PostCode::fromTitle($title);
    }

    /**
     * URL уже существующего поста менять нельзя. Заголовок приходит из
     * машинного перевода и от прогона к прогону гуляет, поэтому пересчёт
     * code при каждом ре-парсинге уводил бы статью на новый адрес, а старый
     * начинал отдавать 404 — вместе с накопленными позициями и внешними
     * ссылками. Переименование остаётся ручной операцией через админку.
     */
    private function keepExistingCode(array $data, ?Post $existing): array
    {
        if ($existing !== null && filled($existing->code)) {
            $data['code'] = $existing->code;
        }

        return $data;
    }

    /**
     * Неудачный ре-парсинг существующего поста не должен затирать статью,
     * добытую прошлой успешной попыткой: updateOrCreate по url записал бы
     * поверх неё пустой контент и служебный заголовок-заглушку. От новой
     * попытки в этом случае берём только отметку о сбое (parse_status /
     * parse_error / parsed_at).
     */
    private function keepExistingContent(array $data, ?Post $existing): array
    {
        if ($existing === null || ! $this->newVersionIsWorse($data, $existing)) {
            return $data;
        }

        unset($data['content'], $data['content_orig'], $data['preview_image'], $data['main_image']);

        $data['title'] = $existing->title;
        $data['code'] = $existing->code;
        $data['category_id'] = $existing->category_id ?: ($data['category_id'] ?? null);
        // Признак движка едет вместе с текстом: оставив его от провалившейся
        // попытки, мы бы подписали сохранённый перевод чужим именем.
        $data['translated_by'] = $existing->translated_by;
        $data['translation_incomplete'] = (bool) $existing->translation_incomplete;

        return $data;
    }

    /**
     * Новая версия статьи хуже сохранённой, и записывать её нельзя.
     *
     * Два случая. Первый — разбор не дал контента вовсе (сбой скачивания,
     * съехавший селектор): исходное назначение этого метода.
     *
     * Второй появился 24.08.2026 и стоил живого перевода. Контент разбор дал,
     * но непереведённый: у модели кончилась суточная квота, скрейпер получил
     * 429 на каждом блоке 44-килобайтной статьи — и поверх полного русского
     * текста поста 236 лёг английский оригинал. Единственным следом осталась
     * галочка «перевод неполный»; восстанавливали из ночного бэкапа.
     *
     * Меряем кириллицей, а не флагом translation_incomplete: флаг сообщает,
     * что о себе думает переводчик, а вопрос стоит о тексте — скрейпер в том
     * инциденте отчитался частичным успехом, то есть по флагу катастрофа не
     * отличалась от лёгкой недоработки.
     *
     * Асимметрия намеренная. Отказ перезаписать в худшем случае лишает нас
     * обновления статьи — оно повторится следующим разбором или правится
     * руками. Перезапись уничтожает работу безвозвратно.
     */
    private function newVersionIsWorse(array $data, Post $existing): bool
    {
        if (blank($existing->content)) {
            return false;
        }

        if (blank($data['content'] ?? null)) {
            return true;
        }

        $before = $this->cyrillicCount((string) $existing->content);

        // Сохранённая статья и так не переведена — терять нечего.
        if ($before === 0) {
            return false;
        }

        // Половина — запас на честную переработку статьи у источника:
        // сокращения и правки бывают, а падение перевода вдвое при живом
        // тексте — почти всегда сломанный переводчик.
        return $this->cyrillicCount((string) $data['content']) < $before * 0.5;
    }

    private function cyrillicCount(string $html): int
    {
        // На невалидном UTF-8 preg_match_all возвращает false, а не 0 — и
        // защита молча выключилась бы ровно на записях с сырым HTML источника
        // (посты до 27.07.2026). Приводим кодировку, как в GeminiTranslator.
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        return (int) preg_match_all('/\p{Cyrillic}/u', $html);
    }

    public function update(PostData $postData, $post): Post
    {
        $data = $postData->toArray();

        try {
            DB::beginTransaction();

            $tagIds = $data['tag_ids'] ?? [];
            unset($data['tag_ids']);

            if (array_key_exists('preview_image', $data) && $data['preview_image'] instanceof UploadedFile) {
                $data['preview_image'] = Storage::disk('public')->put('/images', $data['preview_image']);
            }

            if (array_key_exists('main_image', $data) && $data['main_image'] instanceof UploadedFile) {
                $data['main_image'] = Storage::disk('public')->put('/images', $data['main_image']);
            }

            if (($data['translate'] ?? null) == 'on') {
                // url сохраняем как ключ дедупа/атрибуции (см. store()).
                $data['selector'] = '';
                $data = $this->translateService->translate($data);
            }

            // Адрес уже существующего поста сохраняем: правка заголовка (в том
            // числе автоматическая, при переводе) не должна уводить статью на
            // новый URL и оставлять 404 на старом. Сменить адрес осознанно
            // по-прежнему можно — поле «Код (slug)» в админке правится руками.
            $data['code'] = filled($post->code) ? $post->code : $this->makeCode($data['title']);

            $data['content'] = str_replace('http://laravel.local', '', $data['content']);

            if (empty($data['category_id'])) {
                $data['category_id'] = $this->categoryDetectorService->detect(
                    $data['title'],
                    $post->url ?? '',
                    $data['content'] ?? ''
                );
            }

            if (empty($tagIds)) {
                $tagIds = $this->tagDetectorService->detect($data['title'], $post->url ?? '', $data['content'] ?? '');
            }

            // Как и в store(): словарь молчит — запасной путь через модель.
            if (empty($tagIds)) {
                $tagIds = $this->llmTaggerService->detect($data['title'], $post->url ?? '', $data['content'] ?? '');
            }

            if (empty($data['preview_image']) && ! empty($data['content'])) {
                $imagePath = $this->extractFirstImagePath($data['content']);
                if ($imagePath) {
                    $data['preview_image'] = $imagePath;
                    $data['main_image'] = $imagePath;
                }
            }

            $post->update($data);
            $post->tags()->sync($tagIds);
            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error('PostService::update failed', ['error' => $exception->getMessage()]);
            throw new PostPersistenceException('Не удалось обновить пост', previous: $exception);
        }

        return $post;
    }

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
}
