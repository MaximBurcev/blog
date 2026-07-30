<?php

namespace App\Service;

use App\DataTransferObjects\PostData;
use App\Events\PostCreated;
use App\Exceptions\PostPersistenceException;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostService
{
    public function __construct(
        private readonly TranslateService $translateService,
        private readonly CategoryDetectorService $categoryDetectorService,
        private readonly TagDetectorService $tagDetectorService,
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

            // Пост от неудавшегося парсинга может остаться без осмысленного
            // заголовка — code участвует в публичном URL, пустым он быть не
            // должен.
            $data['code'] = Str::slug($data['title']) ?: 'post-'.Str::random(8);
            $data['selector'] = '';
            $data['content'] = $data['content'] ?? '';

            if (array_key_exists('preview_image', $data) && $data['preview_image'] instanceof \Illuminate\Http\UploadedFile) {
                $data['preview_image'] = Storage::disk('public')->put('/images', $data['preview_image']);
            }

            if (array_key_exists('main_image', $data) && $data['main_image'] instanceof \Illuminate\Http\UploadedFile) {
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
                $data = $this->keepExistingContent($data, Post::where('url', $data['url'])->first());
                $post = Post::updateOrCreate(['url' => $data['url']], $data);
            } else {
                $post = Post::create($data);
            }

            Log::info('PostService::store: post created', ['id' => $post->id, 'title' => $post->title]);

            if (empty($tagIds)) {
                $tagIds = $this->tagDetectorService->detect($data['title'], $data['url'] ?? '', $data['content'] ?? '');
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
     * Неудачный ре-парсинг существующего поста не должен затирать статью,
     * добытую прошлой успешной попыткой: updateOrCreate по url записал бы
     * поверх неё пустой контент и служебный заголовок-заглушку. От новой
     * попытки в этом случае берём только отметку о сбое (parse_status /
     * parse_error / parsed_at).
     */
    private function keepExistingContent(array $data, ?Post $existing): array
    {
        if ($existing === null || filled($data['content'] ?? null) || blank($existing->content)) {
            return $data;
        }

        unset($data['content'], $data['content_orig'], $data['preview_image'], $data['main_image']);

        $data['title'] = $existing->title;
        $data['code'] = $existing->code;
        $data['category_id'] = $existing->category_id ?: ($data['category_id'] ?? null);

        return $data;
    }

    public function update(PostData $postData, $post): Post
    {
        $data = $postData->toArray();

        try {
            DB::beginTransaction();

            $tagIds = $data['tag_ids'] ?? [];
            unset($data['tag_ids']);

            if (array_key_exists('preview_image', $data) && $data['preview_image'] instanceof \Illuminate\Http\UploadedFile) {
                $data['preview_image'] = Storage::disk('public')->put('/images', $data['preview_image']);
            }

            if (array_key_exists('main_image', $data) && $data['main_image'] instanceof \Illuminate\Http\UploadedFile) {
                $data['main_image'] = Storage::disk('public')->put('/images', $data['main_image']);
            }

            if (($data['translate'] ?? null) == 'on') {
                // url сохраняем как ключ дедупа/атрибуции (см. store()).
                $data['selector'] = '';
                $data = $this->translateService->translate($data);
            }

            $data['code'] = Str::slug($data['title'], '-', 'ru');

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
