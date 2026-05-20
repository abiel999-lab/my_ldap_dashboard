<?php

namespace App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;

use App\Filament\Resources\Directory\LdapSchemaEntryResource;
use App\Jobs\Directory\ModifyLdapSchemaDefinitionJob;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Throwable;

class ViewLdapSchemaEntry extends ViewRecord
{
    protected static string $resource = LdapSchemaEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('replaceSchema')
                    ->label('Replace Schema')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->form(fn (): array => LdapSchemaEntryResource::schemaMutationForm($this->record))
                    ->action(fn (array $data) => LdapSchemaEntryResource::queueSchemaMutation('replace', $data, $this->record)),

                Action::make('deleteSchema')
                    ->label('Delete Schema')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP schema definition?')
                    ->modalDescription('Schema definition akan dihapus lewat ldapmodify cn=config. Pastikan schema tidak sedang dipakai.')
                    ->form(fn (): array => [
                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => LdapSchemaEntryResource::connectionOptions())
                            ->default($this->record->ldap_connection_id)
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('schema_type')
                            ->label('Schema Type')
                            ->options(LdapSchemaEntryResource::schemaTypeOptions())
                            ->default($this->record->schema_type)
                            ->required(),

                        Textarea::make('definition')
                            ->label('Definition to delete')
                            ->default((string) ($this->record->raw_definition ?? ''))
                            ->rows(8)
                            ->required(),
                    ])
                    ->action(fn (array $data) => LdapSchemaEntryResource::queueSchemaMutation('delete', $data, $this->record)),
            ])
                ->label('LDAP Operations')
                ->icon('heroicon-o-cog-6-tooth')
                ->button()
                ->color('primary'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->state([
                'id' => $this->record->id,
                'schema_type' => $this->record->schema_type,
                'primary_name' => $this->record->primary_name,
                'display_name' => $this->record->display_name,
                'oid' => $this->record->oid,
                'kind' => $this->record->kind,
                'superior' => $this->record->superior ?: 'N/A',
                'syntax_oid' => $this->record->syntax_oid ?: 'N/A',
                'syntax_description' => $this->record->syntax_description ?: 'N/A',
                'equality_rule' => $this->record->equality_rule ?: 'N/A',
                'ordering_rule' => $this->record->ordering_rule ?: 'N/A',
                'substring_rule' => $this->record->substring_rule ?: 'N/A',
                'is_single_value' => $this->record->is_single_value ? 'Yes' : 'No',
                'is_operational' => $this->record->is_operational ? 'Yes' : 'No',
                'is_obsolete' => $this->record->is_obsolete ? 'Yes' : 'No',
                'status' => $this->record->status,
                'ldap_connection' => LdapSchemaEntryResource::connectionName($this->record->ldap_connection_id),
                'names_text' => implode("\n", array_map(fn ($v) => '- '.$v, (array) ($this->record->names ?? []))),
                'must_text' => implode("\n", array_map(fn ($v) => '- '.$v, (array) ($this->record->must_attributes ?? []))) ?: 'No MUST attributes.',
                'may_text' => implode("\n", array_map(fn ($v) => '- '.$v, (array) ($this->record->may_attributes ?? []))) ?: 'No MAY attributes.',
                'applies_text' => implode("\n", array_map(fn ($v) => '- '.$v, (array) ($this->record->applies_to_attributes ?? []))) ?: 'N/A',
                'description' => $this->record->description ?: 'N/A',
                'raw_definition' => $this->record->raw_definition ?: 'N/A',
            ])
            ->components([
                Tabs::make('Schema Detail')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Overview')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Schema Identity')
                                            ->schema([
                                                TextEntry::make('id')->label('ID')->badge(),
                                                TextEntry::make('schema_type')->label('Schema Type')->badge(),
                                                TextEntry::make('primary_name')->label('Primary Name')->copyable(),
                                                TextEntry::make('display_name')->label('Display Name'),
                                                TextEntry::make('oid')->label('OID')->copyable(),
                                                TextEntry::make('ldap_connection')->label('LDAP Connection')->badge(),
                                                TextEntry::make('status')->label('Status')->badge(),
                                            ]),

                                        Section::make('Classification')
                                            ->schema([
                                                TextEntry::make('kind')->label('Kind')->badge(),
                                                TextEntry::make('superior')->label('Superior')->copyable(),
                                                TextEntry::make('is_single_value')->label('Single Value')->badge(),
                                                TextEntry::make('is_operational')->label('Operational')->badge(),
                                                TextEntry::make('is_obsolete')->label('Obsolete')->badge(),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Rules')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Attribute Type Rules')
                                            ->schema([
                                                TextEntry::make('syntax_oid')->label('Syntax OID')->copyable(),
                                                TextEntry::make('syntax_description')->label('Syntax Description'),
                                                TextEntry::make('equality_rule')->label('Equality Rule')->copyable(),
                                                TextEntry::make('ordering_rule')->label('Ordering Rule')->copyable(),
                                                TextEntry::make('substring_rule')->label('Substring Rule')->copyable(),
                                            ]),

                                        Section::make('ObjectClass / Matching Rule Use')
                                            ->schema([
                                                TextEntry::make('names_text')->label('Names')->copyable(),
                                                TextEntry::make('must_text')->label('MUST Attributes')->copyable(),
                                                TextEntry::make('may_text')->label('MAY Attributes')->copyable(),
                                                TextEntry::make('applies_text')->label('Applies To')->copyable(),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Raw Definition')
                            ->icon('heroicon-o-code-bracket')
                            ->schema([
                                Section::make('Description')
                                    ->schema([
                                        TextEntry::make('description')->label('Description')->copyable(),
                                    ]),

                                Section::make('Raw Definition')
                                    ->schema([
                                        TextEntry::make('raw_definition')
                                            ->label('Definition')
                                            ->copyable()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
