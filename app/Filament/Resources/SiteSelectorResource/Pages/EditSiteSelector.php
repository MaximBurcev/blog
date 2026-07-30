<?php

namespace App\Filament\Resources\SiteSelectorResource\Pages;

use App\Filament\Resources\SiteSelectorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSiteSelector extends EditRecord
{
    protected static string $resource = SiteSelectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
