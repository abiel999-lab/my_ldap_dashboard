<?php

namespace App\Filament\Resources\Directory\DirectoryObjectManagerResource\Pages;

use App\Filament\Resources\Directory\DirectoryObjectManagerResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewDirectoryObject extends ViewRecord
{
    protected static string $resource = DirectoryObjectManagerResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('LDAP Object')
                    ->schema([
                        TextEntry::make('dn')
                            ->label('DN')
                            ->copyable()
                            ->columnSpanFull(),

                        TextEntry::make('ldapConnection.name')
                            ->label('LDAP Connection'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),

                        TextEntry::make('last_seen_at')
                            ->label('Last Seen')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Attributes')
                    ->schema([
                        KeyValueEntry::make('attributes')
                            ->label('Attributes')
                            ->columnSpanFull(),

                        KeyValueEntry::make('raw_attributes')
                            ->label('Raw Attributes')
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => isset($record->raw_attributes)),

                        KeyValueEntry::make('normal_attributes')
                            ->label('Normal Attributes')
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => isset($record->normal_attributes)),
                    ]),
            ]);
    }
}
