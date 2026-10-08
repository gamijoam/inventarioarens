<?php

namespace App\Filament\Resources\SyncInboxes;

use App\Filament\Resources\SyncInboxes\Pages\ManageSyncInboxes;
use App\Modules\Sync\Models\SyncInbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SyncInboxResource extends Resource
{
    protected static ?string $model = SyncInbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Sincronización';

    protected static ?string $navigationLabel = 'Eventos recibidos';

    protected static ?string $modelLabel = 'evento recibido';

    protected static ?string $pluralModelLabel = 'eventos recibidos';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'event_uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('event_uuid')->label('ID del evento')->copyable()->limit(14),
                TextColumn::make('event_type')->label('Tipo de evento')->searchable()->limit(35),
                TextColumn::make('aggregate_type')->label('Registro')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'applied', 'ignored' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('received_at')->label('Recibido')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('last_error')->label('Último error')->limit(55)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options([
                        'received' => 'Recibido',
                        'applied' => 'Aplicado',
                        'ignored' => 'Ignorado',
                        'failed' => 'Fallido',
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSyncInboxes::route('/'),
        ];
    }
}
