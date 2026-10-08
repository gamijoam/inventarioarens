<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemStatus extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        try {
            DB::connection()->getPdo();
            $databaseStatus = 'Disponible';
            $databaseColor = 'success';
        } catch (Throwable) {
            $databaseStatus = 'Sin conexión';
            $databaseColor = 'danger';
        }

        return [
            Stat::make('Aplicación', config('app.name')),
            Stat::make('Entorno', app()->environment()),
            Stat::make('Base de datos', $databaseStatus)->color($databaseColor),
            Stat::make('Laravel', app()->version()),
        ];
    }
}
