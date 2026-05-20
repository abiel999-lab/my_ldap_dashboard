<?php

namespace App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;

use App\Filament\Resources\Operations\LdapTransferBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;

class ViewLdapTransferBatch extends ViewRecord
{
    protected static string $resource = LdapTransferBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview Transfer')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->requiresConfirmation()
                ->action(fn () => LdapTransferBatchResource::queueTransfer($this->record, 'preview')),

            Actions\Action::make('execute')
                ->label('Execute Transfer')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->requiresConfirmation()
                ->action(fn () => LdapTransferBatchResource::queueTransfer($this->record, 'execute')),

            Actions\EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Transfer Detail')
                ->tabs([
                    Tabs\Tab::make('Overview')
                        ->schema([
                            Section::make('Transfer Summary')
                                ->schema([
                                    TextEntry::make('id')->label('ID'),
                                    TextEntry::make('name')->label('Name')->default('N/A'),
                                    TextEntry::make('status')->label('Status')->badge(),
                                    TextEntry::make('mode')->label('Mode')->badge(),
                                    TextEntry::make('sourceConnection.name')->label('Source LDAP')->badge(),
                                    TextEntry::make('targetConnection.name')->label('Target LDAP')->badge(),
                                    TextEntry::make('source_base_dn')->label('Source Base DN')->copyable(),
                                    TextEntry::make('target_base_dn')->label('Target Base DN')->copyable(),
                                    TextEntry::make('ldap_filter')->label('Filter')->copyable(),
                                    TextEntry::make('scope')->label('Scope'),
                                ])
                                ->columns(['default' => 1, 'lg' => 2]),

                            Section::make('Counters')
                                ->schema([
                                    TextEntry::make('total_entries')->label('Total'),
                                    TextEntry::make('success_entries')->label('Success'),
                                    TextEntry::make('failed_entries')->label('Failed'),
                                    TextEntry::make('skipped_entries')->label('Skipped'),
                                ])
                                ->columns(['default' => 1, 'md' => 2, 'xl' => 4]),
                        ]),

                    Tabs\Tab::make('Preview LDIF')
                        ->schema([
                            TextEntry::make('preview_ldif')
                                ->label('Preview LDIF')
                                ->fontFamily('mono')
                                ->copyable()
                                ->columnSpanFull(),
                        ]),

                    Tabs\Tab::make('Output')
                        ->schema([
                            TextEntry::make('stdout')
                                ->label('STDOUT')
                                ->fontFamily('mono')
                                ->copyable()
                                ->columnSpanFull(),

                            TextEntry::make('stderr')
                                ->label('STDERR')
                                ->fontFamily('mono')
                                ->copyable()
                                ->columnSpanFull(),

                            TextEntry::make('error_message')
                                ->label('Error')
                                ->fontFamily('mono')
                                ->copyable()
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
