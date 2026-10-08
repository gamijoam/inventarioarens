<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\SoportePanelProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    SoportePanelProvider::class,
];
