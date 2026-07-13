<?php
// Listar tenants via artisan tinker
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Tenancy\Models\Tenant;

class DebugListTenants extends Command
{
    protected $signature = 'debug:list-tenants';
    protected $description = 'Listar tenants para debug';

    public function handle(): int
    {
        $tenants = Tenant::query()->orderBy('id')->get(['id', 'name', 'slug', 'status', 'plan'])->toArray();
        $this->line('Total tenants: ' . count($tenants));
        $this->line(json_encode($tenants, JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
