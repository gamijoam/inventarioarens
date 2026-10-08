<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemStatus extends StatsOverviewWidget
{
    protected ?string $heading = 'Estado de Balanza Pro';

    protected ?string $description = 'Indicadores operativos de la plataforma y su sincronización.';

    protected function getStats(): array
    {
        try {
            DB::connection()->getPdo();
            $databaseStatus = 'Disponible';
            $databaseColor = 'success';
        } catch (Throwable) {
            $databaseStatus = 'Sin conexión';
            $databaseColor = 'danger';

            return [
                Stat::make('Aplicación', config('app.name')),
                Stat::make('Entorno', app()->environment()),
                Stat::make('Base de datos', $databaseStatus)->color($databaseColor),
                Stat::make('Laravel', app()->version()),
            ];
        }

        $activeTenants = DB::table('tenants')->where('status', 'active')->count();
        $users = DB::table('users')->count();
        $activeNodes = DB::table('sync_nodes')->where('status', 'active')->count();
        $pendingEvents = DB::table('sync_outbox')->where('status', 'pending')->count();
        $failedEvents = DB::table('sync_outbox')->where('status', 'failed')->count()
            + DB::table('sync_inbox')->where('status', 'failed')->count();

        return [
            Stat::make('Aplicación', config('app.name')),
            Stat::make('Entorno', app()->environment()),
            Stat::make('Base de datos', $databaseStatus)->color($databaseColor),
            Stat::make('Laravel', app()->version()),
            Stat::make('Empresas activas', number_format($activeTenants)),
            Stat::make('Usuarios', number_format($users)),
            Stat::make('Dispositivos activos', number_format($activeNodes))
                ->description('Según el estado registrado en la plataforma.'),
            Stat::make('Eventos pendientes', number_format($pendingEvents))
                ->color($pendingEvents > 0 ? 'warning' : 'success'),
            Stat::make('Eventos fallidos', number_format($failedEvents))
                ->color($failedEvents > 0 ? 'danger' : 'success'),
        ];
    }
}
