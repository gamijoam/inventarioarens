<?php

namespace App\Filament\Resources\SyncNodes;

use App\Filament\Resources\SyncNodes\Pages\ManageSyncNodes;
use App\Modules\Sync\Models\SyncNode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SyncNodeResource extends Resource
{
    protected static ?string $model = SyncNode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Sincronización';

    protected static ?string $navigationLabel = 'Dispositivos';

    protected static ?string $modelLabel = 'dispositivo';

    protected static ?string $pluralModelLabel = 'dispositivos';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('name')->label('Dispositivo')->searchable()->sortable(),
                TextColumn::make('code')->label('Código')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('last_seen_at')->label('Última conexión')
                    ->dateTime('d/m/Y H:i')->placeholder('Sin conexión registrada')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(['active' => 'Activo', 'inactive' => 'Inactivo']),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(['local' => 'Local', 'cloud' => 'Nube']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSyncNodes::route('/'),
        ];
    }
}
