<?php

namespace App\Filament\Resources\Observability;

use App\Filament\Resources\Observability\AuditLogResource\Pages;
use App\Models\Audit\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = '5. Observability';

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?string $modelLabel = 'Audit Log';

    protected static ?string $pluralModelLabel = 'Audit Logs';

    protected static ?int $navigationSort = 10;

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
                Section::make('Actor')
                    ->schema([
                        TextEntry::make('actor_name')->label('Name')->placeholder('System / Unknown'),
                        TextEntry::make('actor_email')->label('Email')->placeholder('No email'),
                        TextEntry::make('actor_ip')->label('IP Address')->placeholder('No IP'),
                        TextEntry::make('user_agent')->label('User Agent')->placeholder('No user agent')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Action')
                    ->schema([
                        TextEntry::make('module')->label('Module')->badge(),
                        TextEntry::make('action')->label('Action')->badge(),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('N/A'),
                        TextEntry::make('target_type')->label('Target Type')->placeholder('N/A'),
                        TextEntry::make('target_key')->label('Target Key')->placeholder('N/A'),
                        TextEntry::make('target_dn')->label('Target DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('error_message')->label('Error Message')->placeholder('No error')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Payload')
                    ->schema([
                        TextEntry::make('before_value')
                            ->label('Before')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('after_value')
                            ->label('After')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('request_payload')
                            ->label('Request Payload')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Command Output')
                    ->schema([
                        TextEntry::make('command')->label('Command')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('stdout')->label('STDOUT')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('stderr')->label('STDERR')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('exit_code')->label('Exit Code')->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Timestamp')
                    ->schema([
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('uuid')->label('UUID'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('actor_email')
                    ->label('Actor')
                    ->searchable()
                    ->placeholder('System'),

                TextColumn::make('target_key')
                    ->label('Target')
                    ->searchable()
                    ->placeholder('N/A'),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->limit(45)
                    ->searchable()
                    ->placeholder('N/A'),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms')
                    ->sortable()
                    ->placeholder('N/A'),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
