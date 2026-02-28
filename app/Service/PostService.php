<?php

namespace App\Service;

use App\Events\UserNotification;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCreatedNotification;
use App\Service\CategoryDetectorService;
use App\Service\TagDetectorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostService
{
    public function store($data): void
    {

        Log::info('PostService::store', $data);

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
                $translateService = new TranslateService($data);
                $data = $translateService->translate();
                $data['url'] = '';
            }

            if (empty($data['category_id'])) {
                $data['category_id'] = (new CategoryDetectorService())->detect(
                    $data['title'],
                    $data['url'] ?? ''
                );
            }

            $post = Post::create($data);

            Log::info('$data', $data);

            if (empty($tagIds)) {
                $tagIds = (new TagDetectorService())->detect($data['title'], $data['url'] ?? '', $data['content'] ?? '');
            }

            if (!empty($tagIds)) {
                $post->tags()->attach($tagIds);
            }

            DB::commit();
        } catch (\Exception $exception) {
            dd($exception->getMessage());
            logger($exception->getMessage());
            DB::rollBack();
            abort(404);
        }

        $message = 'Создан новый пост: ' . $post->title;
        User::where('role', 0)->each(function (User $user) use ($post, $message) {
            try {
                UserNotification::dispatch($user, $message);
            } catch (\Exception $e) {
                Log::warning('UserNotification: broadcast failed', ['error' => $e->getMessage()]);
            }
            try {
                $user->notify(new PostCreatedNotification($post));
            } catch (\Exception $e) {
                Log::warning('PostCreatedNotification: mail failed', ['error' => $e->getMessage()]);
            }
        });
    }

    public function update($data, $post): Post
    {


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
                $translateService = new TranslateService($data);
                $data = $translateService->translate();

            }

            $data['code'] = Str::slug($data['title'], '-', 'ru');

            if (empty($data['category_id'])) {
                $data['category_id'] = (new CategoryDetectorService())->detect(
                    $data['title'],
                    $post->url ?? ''
                );
            }

            $data['content'] = str_replace('http://laravel.local', '', $data['content']);

            if (empty($tagIds)) {
                $tagIds = (new TagDetectorService())->detect($data['title'], $post->url ?? '', $data['content'] ?? '');
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
            dd($exception->getMessage());
            DB::rollBack();
            abort(500);
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
