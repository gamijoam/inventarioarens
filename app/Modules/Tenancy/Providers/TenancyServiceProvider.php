<?php

namespace App\Modules\Tenancy\Providers;

use App\Support\Tenancy\TenantManager;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantManager::class, fn () => new TenantManager());
    }
}
