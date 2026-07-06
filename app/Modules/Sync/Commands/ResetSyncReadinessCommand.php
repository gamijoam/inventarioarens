<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetSyncReadinessCommand extends Command
{
    protected $signature = 'sync:reset-readiness
        {tenant? : Slug de la empresa a reiniciar}
        {--installation= : Codigo de instalacion local especifico}
        {--all : Reinicia todas las empresas e instalaciones locales}';

    protected $description = 'Reinicia solo el estado local de preparacion de sincronizacion.';

    public function handle(): int
    {
        if (! Schema::hasTable('sync_tenant_readiness')) {
            $this->info('Estado local de sincronizacion reiniciado.');
            $this->line('Registros eliminados: 0');
            $this->warn('La tabla sync_tenant_readiness aun no existe. Ejecuta las migraciones antes de probar la sincronizacion inicial.');

            return self::SUCCESS;
        }

        $all = (bool) $this->option('all');
        $tenantSlug = $this->argument('tenant');
        $installationCode = $this->option('installation');

        if (! $all && ! $tenantSlug) {
            $this->error('Indica una empresa o usa --all para reiniciar todos los estados locales.');

            return self::FAILURE;
        }

        $query = DB::table('sync_tenant_readiness');

        if (! $all) {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

            if (! $tenant) {
                $this->error('No se encontro la empresa indicada.');

                return self::FAILURE;
            }

            $query->where('tenant_id', $tenant->id);
        }

        if ($installationCode) {
            $query->where('installation_code', (string) $installationCode);
        }

        $deleted = $query->delete();

        $this->info('Estado local de sincronizacion reiniciado.');
        $this->line('Registros eliminados: '.$deleted);

        return self::SUCCESS;
    }
}
