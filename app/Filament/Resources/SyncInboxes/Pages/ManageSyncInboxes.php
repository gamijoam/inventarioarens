<?php

namespace App\Filament\Resources\SyncInboxes\Pages;

use App\Filament\Resources\SyncInboxes\SyncInboxResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSyncInboxes extends ManageRecords
{
    protected static string $resource = SyncInboxResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
