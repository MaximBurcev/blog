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
    ) {
    }

    public function store(PostData $postData): void
    {
        $data = $postData->toArray();

        Log::info('PostService::store', ['title' => $data['title'] ?? null, 'url' => $data['url'] ?? null]);

        $post = null;

        try {
            DB::beginTransaction();
            $tagIds = $data['tag_ids'] ?? [];
            unset($data['tag_ids'], $data['html_file']);

            $data['code'] = Str::slug($data['title']);
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
                $data['url'] = '';
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
                $post = Post::updateOrCreate(['url' => $data['url']], $data);
            } else {
                $post = Post::create($data);
            }

            Log::info('PostService::store: post created', ['id' => $post->id, 'title' => $post->title]);

            if (empty($tagIds)) {
                $tagIds = $this->tagDetectorService->detect($data['title'], $data['url'] ?? '', $data['content'] ?? '');
            }

            if (!empty($tagIds)) {
                $post->tags()->attach($tagIds);
            }

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error('PostService::store failed', ['error' => $exception->getMessage()]);
            throw new PostPersistenceException('Не удалось сохранить пост', previous: $exception);
        }

        PostCreated::dispatch($post);
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
                $data['url'] = '';
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

            if (empty($data['preview_image']) && !empty($data['content'])) {
                $imagePath = $this->extractFirstImagePath($data['content']);
                if ($imagePath) {
                    $data['preview_image'] = $imagePath;
                    $data['main_image']    = $imagePath;
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
        if (!preg_match('/<img[^>]+src="([^"]+)"/i', $content, $matches)) {
            return null;
        }

        $url        = $matches[1];
        $storageUrl = rtrim(Storage::disk('public')->url(''), '/') . '/';

        if (!str_starts_with($url, $storageUrl)) {
            return null;
        }

        return str_replace($storageUrl, '', $url);
    }
}
