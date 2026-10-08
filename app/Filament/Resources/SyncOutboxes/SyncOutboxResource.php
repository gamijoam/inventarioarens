<?php

namespace App\Filament\Resources\SyncOutboxes;

use App\Filament\Resources\SyncOutboxes\Pages\ManageSyncOutboxes;
use App\Modules\Sync\Models\SyncOutbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SyncOutboxResource extends Resource
{
    protected static ?string $model = SyncOutbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Sincronización';

    protected static ?string $navigationLabel = 'Eventos enviados';

    protected static ?string $modelLabel = 'evento enviado';

    protected static ?string $pluralModelLabel = 'eventos enviados';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'event_uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('event_uuid')->label('ID del evento')->copyable()->limit(14),
                TextColumn::make('event_type')->label('Tipo de evento')->searchable()->limit(35),
                TextColumn::make('target_scope')->label('Destino')->badge(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('attempts')->label('Intentos')->sortable(),
                TextColumn::make('available_at')->label('Disponible desde')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('last_error')->label('Último error')->limit(55)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'processed' => 'Procesado',
                        'failed' => 'Fallido',
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSyncOutboxes::route('/'),
        ];
    }
}
