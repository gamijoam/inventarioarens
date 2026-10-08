<?php

namespace App\Filament\Resources\SyncNodes\Pages;

use App\Filament\Resources\SyncNodes\SyncNodeResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSyncNodes extends ManageRecords
{
    protected static string $resource = SyncNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
