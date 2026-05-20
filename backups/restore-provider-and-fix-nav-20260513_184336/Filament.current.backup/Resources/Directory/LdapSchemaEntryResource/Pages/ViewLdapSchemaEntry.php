<?php

namespace App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;

use App\Filament\Resources\Directory\LdapSchemaEntryResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ViewLdapSchemaEntry extends ViewRecord
{
    protected static string $resource = LdapSchemaEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\ActionGroup::make([
                \Filament\Actions\Action::make('replaceSchema')
                    ->label('Replace Schema')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->form(fn (): array => LdapSchemaEntryResource::schemaMutationForm($this->record))
                    ->action(fn (array $data) => LdapSchemaEntryResource::queueSchemaMutation('replace', $data, $this->record)),

                \Filament\Actions\Action::make('deleteSchema')
                    ->label('Delete Schema')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP schema definition?')
                    ->modalDescription('Ini akan menghapus schema definition dari cn=config. Pastikan schema tidak sedang dipakai entry LDAP.')
                    ->form(fn (): array => LdapSchemaEntryResource::schemaDeleteForm($this->record))
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
        $names = $this->arrayText($this->record->names ?? []);
        $must = $this->arrayText($this->record->must_attributes ?? []);
        $may = $this->arrayText($this->record->may_attributes ?? []);
        $applies = $this->arrayText($this->record->applies_to_attributes ?? []);

        return $schema
            ->state([
                'id' => $this->record->id,
                'schema_type' => LdapSchemaEntryResource::safeValue($this->record, ['schema_type', 'type'], 'unknown'),
                'primary_name' => LdapSchemaEntryResource::safeValue($this->record, ['primary_name', 'name', 'display_name'], 'N/A'),
                'display_name' => LdapSchemaEntryResource::safeValue($this->record, ['display_name', 'primary_name', 'name'], 'N/A'),
                'oid' => LdapSchemaEntryResource::safeValue($this->record, ['oid'], 'N/A'),
                'kind' => LdapSchemaEntryResource::safeValue($this->record, ['kind'], 'N/A'),
                'superior' => LdapSchemaEntryResource::safeValue($this->record, ['superior'], 'N/A'),
                'syntax_oid' => LdapSchemaEntryResource::safeValue($this->record, ['syntax_oid'], 'N/A'),
                'equality_rule' => LdapSchemaEntryResource::safeValue($this->record, ['equality_rule'], 'N/A'),
                'ordering_rule' => LdapSchemaEntryResource::safeValue($this->record, ['ordering_rule'], 'N/A'),
                'substring_rule' => LdapSchemaEntryResource::safeValue($this->record, ['substring_rule'], 'N/A'),
                'is_single_value' => ((bool) ($this->record->is_single_value ?? false)) ? 'Yes' : 'No',
                'is_operational' => ((bool) ($this->record->is_operational ?? false)) ? 'Yes' : 'No',
                'is_obsolete' => ((bool) ($this->record->is_obsolete ?? false)) ? 'Yes' : 'No',
                'status' => LdapSchemaEntryResource::safeValue($this->record, ['status'], 'active'),
                'ldap_connection' => LdapSchemaEntryResource::connectionName($this->record->ldap_connection_id ?? null),
                'names_text' => $names ?: 'N/A',
                'must_text' => $must ?: 'No MUST attributes.',
                'may_text' => $may ?: 'No MAY attributes.',
                'applies_text' => $applies ?: 'N/A',
                'description' => LdapSchemaEntryResource::safeValue($this->record, ['description'], 'N/A'),
                'raw_definition' => LdapSchemaEntryResource::safeValue($this->record, ['raw_definition', 'raw'], 'N/A'),
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

    private function arrayText($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (! is_array($value)) {
            return '';
        }

        return collect($value)
            ->map(fn ($item): string => '- '.(is_array($item) ? json_encode($item) : (string) $item))
            ->implode("\n");
    }
}
