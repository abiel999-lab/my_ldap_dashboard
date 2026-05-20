<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Ldap\LdapDirectoryBrowserService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LdapDirectoryEntryResource extends Resource
{
    protected static ?string $model = LdapDirectoryEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?string $navigationLabel = 'Directory Browser';

    protected static ?string $modelLabel = 'LDAP Entry';

    protected static ?string $pluralModelLabel = 'LDAP Directory Browser';

    protected static ?int $navigationSort = 20;

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
                Section::make('Entry Identity')
                    ->schema([
                        TextEntry::make('connection_name')->label('Connection')->badge(),
                        TextEntry::make('entry_type')->label('Type')->badge(),
                        TextEntry::make('entry_rdn')->label('RDN'),
                        TextEntry::make('depth')->label('Depth'),
                        TextEntry::make('entry_dn')->label('Entry DN')->columnSpanFull(),
                        TextEntry::make('parent_dn')->label('Parent DN')->placeholder('Root / none')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Object Classes')
                    ->schema([
                        TextEntry::make('object_classes')
                            ->label('Object Classes')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')
                            ->columnSpanFull(),
                    ]),

                Section::make('Attributes')
                    ->schema([
                        TextEntry::make('attributes')
                            ->label('Attributes')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')
                            ->columnSpanFull(),
                    ]),

                Section::make('Sync Metadata')
                    ->schema([
                        TextEntry::make('source_hash')->label('Source Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('last_seen_at')->label('Last Seen')->dateTime()->placeholder('Never'),
                        TextEntry::make('updated_at')->label('Updated')->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('depth')->orderBy('entry_dn'))
            ->columns([
                TextColumn::make('connection_name')
                    ->label('Connection')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('entry_rdn')
                    ->label('RDN')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('entry_dn')
                    ->label('DN')
                    ->searchable()
                    ->limit(70)
                    ->tooltip(fn (LdapDirectoryEntry $record): string => $record->entry_dn),

                TextColumn::make('parent_dn')
                    ->label('Parent DN')
                    ->searchable()
                    ->limit(45)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('depth')
                    ->label('Depth')
                    ->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapDirectoryEntries::route('/'),
            'view' => Pages\ViewLdapDirectoryEntry::route('/{record}'),
        ];
    }
}
