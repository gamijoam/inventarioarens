<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\FixSpinoffParentCommand;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantManager::class, fn () => new TenantManager);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FixSpinoffParentCommand::class,
            ]);
        }
    }
}
