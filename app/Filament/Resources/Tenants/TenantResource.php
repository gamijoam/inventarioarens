<?php

namespace App\Filament\Resources\Tenants;

use App\Filament\Resources\Tenants\Pages\ManageTenants;
use App\Modules\Tenancy\Models\Tenant;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $modelLabel = 'empresa';

    protected static ?string $pluralModelLabel = 'empresas';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('users');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Empresa'),
            TextEntry::make('slug')->label('Identificador'),
            TextEntry::make('domain')->label('Dominio'),
            TextEntry::make('status')->label('Estado'),
            TextEntry::make('plan')->label('Plan'),
            TextEntry::make('users_count')->label('Usuarios'),
            TextEntry::make('created_at')->label('Creada')->dateTime('d/m/Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('slug')->label('Identificador')->searchable(),
                TextColumn::make('domain')->label('Dominio')->placeholder('Sin dominio'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('plan')->label('Plan')->placeholder('Sin plan'),
                TextColumn::make('users_count')->label('Usuarios')->sortable(),
                TextColumn::make('created_at')->label('Creada')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'active' => 'Activa',
                        'inactive' => 'Inactiva',
                        'suspended' => 'Suspendida',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenants::route('/'),
        ];
    }
}
