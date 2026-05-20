<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use App\Services\Operations\SmartImportTemplateResolver;
class ListImportTemplates extends ListRecords
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('createSmartTemplate')
                ->label('New Smart Template')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading('Create Smart LDAP Import Template')
                ->modalWidth('4xl')
                ->schema([
                    \Filament\Schemas\Components\Section::make('1. What do you want to generate?')
                        ->description('Pilih LDAP, operasi, object type, dan format. Sistem akan mengatur objectClass, MUST attribute, DN rule, dan sample file.')
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('template_name')
                                ->label('Template Name')
                                ->placeholder('Auto generated if empty'),

                            \Filament\Forms\Components\Select::make('ldap_connection_id')
                                ->label('Target LDAP')
                                ->options(function (): array {
                                    if (! Schema::hasTable('ldap_connections')) {
                                        return [];
                                    }

                                    return DB::table('ldap_connections')
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
                                        ->all();
                                })
                                ->searchable()
                                ->preload()
                                ->required(),

                            \Filament\Forms\Components\Select::make('operation_mode')
                                ->label('Operation')
                                ->options([
                        'create' => 'Create entry',
                        'update' => 'Update attributes',
                        'delete' => 'Delete entry',
                        'rename_dn' => 'Rename DN / RDN',
                        'move_dn' => 'Move DN to another parent',
                        'add_objectclass' => 'Add objectClass',
                        'schema_create' => 'Schema create',
                    ])
                                ->default('create')
                                ->required(),

                            \Filament\Forms\Components\Select::make('file_format')
                                ->label('File Format')
                                ->options([
                                    'csv' => 'CSV',
                                    'ldif' => 'LDIF',
                                    'json' => 'JSON',
                                ])
                                ->default('csv')
                                ->required(),

                            \Filament\Forms\Components\Select::make('object_type')
                                ->label('Object Type')
                                ->options([
                                    'user' => 'User / inetOrgPerson',
                                    'group' => 'Group / groupOfNames',
                                    'ou' => 'Organizational Unit',
                                    'device' => 'Device',
                                    'service' => 'Service Account',
                                    'custom' => 'Custom LDAP Object',
                                ])
                                ->default('user')
                                ->required(),
                        ])
                        ->columns(2),

                    \Filament\Schemas\Components\Section::make('2. Optional overrides')
                        ->description('Boleh dikosongi. Sistem otomatis membaca struktur LDAP dan schema. Isi hanya jika ingin override.')
                        ->collapsed()
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('base_dn')
                                ->label('Base DN Override')
                                ->placeholder('dc=petra,dc=ac,dc=id'),

                            \Filament\Forms\Components\TextInput::make('parent_dn')
                                ->label('Parent DN Override')
                                ->placeholder('ou=people,{base_dn}'),

                            \Filament\Forms\Components\TextInput::make('rdn_attribute')
                                ->label('RDN Attribute Override')
                                ->placeholder('uid / cn / ou'),

                            \Filament\Forms\Components\TextInput::make('dn_template')
                                ->label('DN Template Override')
                                ->placeholder('uid={uid},ou=people,{base_dn}'),

                            \Filament\Forms\Components\Textarea::make('object_classes')
                                ->label('Custom ObjectClasses')
                                ->rows(4)
                                ->placeholder("top\ncustomObjectClass")
                                ->helperText('Hanya dipakai jika Object Type = Custom.'),

                            \Filament\Forms\Components\Textarea::make('auxiliary_object_classes')
                                ->label('Auxiliary ObjectClasses')
                                ->rows(3)
                                ->placeholder("petraPerson\neduPerson")
                                ->helperText('Opsional. MUST attribute dari auxiliary akan otomatis ikut.'),

                            \Filament\Forms\Components\Textarea::make('required_attributes')
                                ->label('Extra Required Attributes')
                                ->rows(3)
                                ->placeholder("petraNrp\npetraAffiliation"),

                            \Filament\Forms\Components\Textarea::make('optional_attributes')
                                ->label('Extra Optional Attributes')
                                ->rows(3)
                                ->placeholder("description\ntelephoneNumber"),

                            \Filament\Forms\Components\TextInput::make('sample_rows')
                                ->label('Sample Rows')
                                ->numeric()
                                ->default(3),

                            \Filament\Forms\Components\Select::make('if_target_exists')
                                ->label('If Target Exists')
                                ->options([
                                    'skip' => 'Skip existing target',
                                    'update' => 'Update existing target',
                                    'fail' => 'Fail if target exists',
                                ])
                                ->default('skip'),

                            \Filament\Forms\Components\Toggle::make('allow_destructive_operation')
                                ->label('Allow destructive operation')
                                ->default(false),
                        ])
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    $resolver = app(SmartImportTemplateResolver::class);
                    $metadata = $resolver->buildMetadata($data);

                    $name = filled($data['template_name'] ?? null)
                        ? (string) $data['template_name']
                        : 'Smart '.ucfirst((string) ($data['object_type'] ?? 'LDAP')).' '.strtoupper((string) ($data['file_format'] ?? 'CSV')).' Template';

                    $columns = Schema::getColumnListing('import_templates');

                    $record = [
                        'uuid' => (string) Str::uuid(),
                        'name' => $name,
                        'ldap_connection_id' => $data['ldap_connection_id'] ?? null,
                        'template_purpose' => $metadata['operation_mode'] ?? 'create',
                        'purpose' => $metadata['operation_mode'] ?? 'create',
                        'file_format' => $metadata['file_format'] ?? 'csv',
                        'format' => $metadata['file_format'] ?? 'csv',
                        'entry_type' => $metadata['object_type'] ?? 'user',
                        'status' => 'draft',
                        'base_dn' => $metadata['dn_rules']['base_dn'] ?? null,
                        'target_ou' => $metadata['dn_rules']['target_parent_dn'] ?? null,
                        'identifier_attribute' => $metadata['dn_rules']['rdn_attribute'] ?? null,
                        'rdn_attribute' => $metadata['dn_rules']['rdn_attribute'] ?? null,
                        'dn_template' => $metadata['dn_rules']['dn_template'] ?? null,
                        'object_classes' => $metadata['object_rules']['object_classes'] ?? [],
                        'required_attributes' => $metadata['object_rules']['required_attributes'] ?? [],
                        'optional_attributes' => $metadata['object_rules']['optional_attributes'] ?? [],
                        'additional_attributes' => $metadata['object_rules']['optional_attributes'] ?? [],
                        'attribute_mapping' => $metadata['object_rules']['attribute_mapping'] ?? [],
                        'multi_value_separator' => $metadata['object_rules']['multi_value_separator'] ?? ';',
                        'sample_rows' => $metadata['safety_rules']['sample_rows'] ?? 3,
                        'metadata' => $metadata,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $columnTypes = collect(DB::select("
                        select column_name, data_type, udt_name
                        from information_schema.columns
                        where table_schema = 'public'
                        and table_name = 'import_templates'
                    "))
                        ->mapWithKeys(fn ($column) => [
                            $column->column_name => strtolower((string) ($column->data_type ?: $column->udt_name)),
                        ])
                        ->all();

                    $jsonColumns = collect($columnTypes)
                        ->filter(fn ($type) => str_contains($type, 'json'))
                        ->keys()
                        ->all();

                    $clean = [];

                    foreach ($record as $key => $value) {
                        if (! in_array($key, $columns, true)) {
                            continue;
                        }

                        if (in_array($key, $jsonColumns, true)) {
                            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        } elseif (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        }

                        $clean[$key] = $value;
                    }

                    DB::table('import_templates')->insert($clean);

                    \Filament\Notifications\Notification::make()
                        ->title('Smart template created')
                        ->body('Template rules were generated from LDAP schema/fallback rules.')
                        ->success()
                        ->send();
                }),
        ];
    }


}
