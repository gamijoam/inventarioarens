<?php

namespace App\Filament\Resources\SyncOutboxes\Pages;

use App\Filament\Resources\SyncOutboxes\SyncOutboxResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSyncOutboxes extends ManageRecords
{
    protected static string $resource = SyncOutboxResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
