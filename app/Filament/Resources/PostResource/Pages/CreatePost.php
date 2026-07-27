<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\DataTransferObjects\PostData;
use App\Filament\Resources\PostResource;
use App\Service\PostService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /**
     * Дефолтный CreateRecord::handleRecordCreation() делает голый
     * Post::create($data) — минуя PostService, пост, созданный тут, не
     * получал ни авто-детект тегов, ни событие PostCreated (в отличие от
     * скрейпинг-пайплайна StorePostJob, который тоже зовёт PostService).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(PostService::class)->store(PostData::fromArray($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
