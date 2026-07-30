<?php

namespace App\Filament\Resources\ReleaseResource\Pages;

use App\Filament\Resources\ReleaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRelease extends CreateRecord
{
    protected static string $resource = ReleaseResource::class;

    /**
     * Релиз заводят ради его разбора, поэтому парсинг стартует сразу после
     * создания — так же вёл себя прежний Admin\Release\StoreController.
     */
    protected function afterCreate(): void
    {
        ReleaseResource::dispatchParse($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
