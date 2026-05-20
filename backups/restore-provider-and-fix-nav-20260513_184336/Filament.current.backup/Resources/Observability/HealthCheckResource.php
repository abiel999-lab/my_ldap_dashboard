<?php

namespace App\Filament\Resources\Observability;

use App\Filament\Resources\Observability\HealthCheckResource\Pages;
use App\Models\Observability\HealthCheck;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class HealthCheckResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '3. Observability';
    protected static ?int $navigationSort = 40;

    protected static ?string $model = HealthCheck::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';
    protected static ?string $navigationLabel = 'Health Checks';

    protected static ?string $modelLabel = 'Health Check';

    protected static ?string $pluralModelLabel = 'Health Checks';
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Health Check')
                    ->schema([
                        TextEntry::make('component')->label('Component')->badge(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'healthy' => 'success',
                                'warning' => 'warning',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('N/A'),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Details')
                    ->schema([
                        TextEntry::make('details')
                            ->label('Details')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('checked_at')->label('Checked At')->dateTime()->placeholder('Never'),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('component')->orderBy('name'))
            ->columns([
                TextColumn::make('component')
                    ->label('Component')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('message')
                    ->label('Message')
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms')
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('checked_at')
                    ->label('Checked At')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('component')
                    ->label('Component')
                    ->options([
                        'app' => 'App',
                        'database' => 'Database',
                        'queue' => 'Queue',
                        'ldap' => 'LDAP',
                        'keycloak' => 'Keycloak',
                        'radius' => 'FreeRADIUS',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'failed' => 'Failed',
                        'unknown' => 'Unknown',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHealthChecks::route('/'),
            'view' => Pages\ViewHealthCheck::route('/{record}'),
        ];
    }
}
