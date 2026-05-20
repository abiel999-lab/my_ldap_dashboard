<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\EditImportTemplate;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\ListImportTemplates;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\ViewImportTemplate;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\ImportTemplate;
use App\Services\Operations\ImportTemplateGeneratorService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

use Illuminate\Support\Facades\Storage;
class ImportTemplateResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '2. OPERATIONS';
    protected static ?int $navigationSort = 40;

    protected static ?string $model = ImportTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';
    protected static ?string $navigationLabel = 'Import Templates';

    protected static ?string $modelLabel = 'Import Template';

    protected static ?string $pluralModelLabel = 'Import Templates';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->label('Template Name')
                ->default('User Import Template')
                ->required()
                ->maxLength(255),

            Select::make('ldap_connection_id')
                ->label('LDAP Connection')
                ->options(fn (): array => class_exists(LdapConnection::class)
                    ? LdapConnection::query()
                        ->orderByDesc('is_default')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    : [])
                ->searchable()
                ->preload()
                ->helperText('Optional. Choose LDAP connection for schema-aware template later.'),

            Select::make('template_purpose')
                ->label('Template Purpose')
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

            Select::make('entry_type')
                ->label('Entry Type')
                ->options([
                    'user' => 'User / inetOrgPerson',
                    'group' => 'Group / groupOfNames',
                    'ou' => 'Organizational Unit',
                    'custom' => 'Custom Entry',
                ])
                ->default('user')
                ->required(),

            Select::make('file_format')
                ->label('File Format')
                ->options([
                    'csv' => 'CSV',
                    'ldif' => 'LDIF',
                    'json' => 'JSON',
                ])
                ->default('csv')
                ->required(),

            TextInput::make('base_dn')
                ->label('Base DN')
                        ->helperText('Tidak hardcode Petra. Isi sesuai LDAP Connection target, misalnya dc=test,dc=local atau dc=company,dc=com.')
                ->placeholder('dc=example,dc=local')
                ->required(),

            TextInput::make('target_ou')
                ->label('Target OU')
                ->placeholder('people, groups, services, devices, atau kosong')
                ->helperText('Example: people. Leave empty if DN is directly under Base DN.'),

            TextInput::make('rdn_attribute')
                ->label('RDN / Identifier Attribute')
                ->placeholder('uid, cn, ou, atau attribute RDN lain')
                ->required()
                ->helperText('Users usually uid. Groups usually cn. OU usually ou.'),

            Textarea::make('object_classes_text')
                ->label('Object Classes')
                ->default("top\nperson\norganizationalPerson\ninetOrgPerson")
                ->helperText('One per line. Example: add petraPerson if schema supports it.')
                ->rows(6)
                ->dehydrated(false)
                ->afterStateHydrated(function (Textarea $component, $state, ?ImportTemplate $record): void {
                    if ($record) {
                        $component->state(implode("\n", $record->object_classes ?? []));
                    }
                }),

            Textarea::make('attributes_text')
                ->label('Additional Custom Attributes')
                ->helperText('One per line. Example: petraNrp, petraAffiliation, petraFaculty.')
                ->rows(6)
                ->dehydrated(false)
                ->afterStateHydrated(function (Textarea $component, $state, ?ImportTemplate $record): void {
                    if ($record) {
                        $component->state(implode("\n", $record->attributes ?? []));
                    }
                }),

            TextInput::make('multi_value_separator')
                ->label('Multi-value Separator')
                ->default(';')
                ->required(),

            TextInput::make('sample_rows')
                ->label('Sample Rows')
                ->numeric()
                ->default(3)
                ->minValue(1)
                ->maxValue(20)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->limit(45),

                TextColumn::make('template_purpose')
                    ->label('Purpose')
                    ->badge()
                    ->sortable(),

                TextColumn::make('file_format')
                    ->label('Format')
                    ->badge()
                    ->sortable(),

                TextColumn::make('entry_type')
                    ->label('Entry')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('output_filename')
                    ->label('Output')
                    ->limit(35)
                    ->placeholder('N/A')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])


            ->bulkActions([
                \Filament\Actions\BulkAction::make('delete_selected_import_templates')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected import templates?')
                    ->modalDescription('This only deletes template records from the application. Existing import batches are not deleted.')
                    ->modalSubmitActionLabel('Delete Selected')
                    ->action(function ($records): void {
                        foreach ($records as $record) {
                            $record->delete();
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Selected import templates deleted')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])

            ->recordActions([
                Action::make('generateTemplate')
                    ->label('Generate')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('success')
                    ->action(function (ImportTemplate $record): void {
                        try {
                            $result = app(ImportTemplateGeneratorService::class)->generate($record);

                            Notification::make()
                                ->title(($result['ok'] ?? false) ? 'Template generated' : 'Generate failed')
                                ->body($result['message'] ?? 'Template generation finished.')
                                ->color(($result['ok'] ?? false) ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);

                            Notification::make()
                                ->title('Generate failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (ImportTemplate $record): ?string => $record->download_url)
                    ->openUrlInNewTab(false)
                    ->visible(fn (ImportTemplate $record): bool => filled($record->output_path)),

                ViewAction::make(),

                EditAction::make(),
            ]);
    }

    public static function normalizeFormData(array $data): array
    {
        $data['object_classes'] = static::splitTextarea($data['object_classes_text'] ?? null);
        $data['attributes'] = static::splitTextarea($data['attributes_text'] ?? null);

        unset($data['object_classes_text'], $data['attributes_text']);

        $data['status'] = $data['status'] ?? 'draft';

        return $data;
    }

    private static function splitTextarea(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/[\r\n,;|]+/', $value) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }


    public static function decodeTemplateMetadata(mixed $record): array
    {
        $metadata = $record->metadata ?? [];

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    public static function templateLines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            )));
        }

        if (blank($value)) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $decoded
            )));
        }

        return array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            preg_split('/\r?\n/', (string) $value) ?: []
        )));
    }

    public static function templateKeyValueLines(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $items = [];

        foreach (static::templateLines($value) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $line, 2);
            $items[trim($key)] = trim($val);
        }

        return $items;
    }

    public static function buildGenericImportTemplateCsvFromRecord(mixed $record): string
    {
        $metadata = static::decodeTemplateMetadata($record);

        $operationMode = $metadata['operation_mode']
            ?? $metadata['operation']['mode']
            ?? $record->template_purpose
            ?? $record->purpose
            ?? 'create';

        $fileFormat = $metadata['file_format']
            ?? $record->file_format
            ?? $record->format
            ?? 'csv';

        $baseDn = $metadata['dn_rules']['base_dn']
            ?? $record->base_dn
            ?? $record->target_base_dn
            ?? 'dc=example,dc=local';

        $targetParentDn = $metadata['dn_rules']['target_parent_dn']
            ?? $metadata['dn_rules']['target_ou']
            ?? $record->target_ou
            ?? $record->parent_dn
            ?? '';

        $rdnAttribute = $metadata['dn_rules']['rdn_attribute']
            ?? $metadata['dn_rules']['identifier_attribute']
            ?? $record->rdn_attribute
            ?? $record->identifier_attribute
            ?? 'uid';

        $dnTemplate = $metadata['dn_rules']['dn_template']
            ?? $record->dn_template
            ?? null;

        if (blank($dnTemplate)) {
            $parent = filled($targetParentDn)
                ? str_replace('{base_dn}', $baseDn, (string) $targetParentDn)
                : $baseDn;

            $dnTemplate = $rdnAttribute.'={'.$rdnAttribute.'},'.$parent;
        }

        $objectClasses = static::templateLines(
            $metadata['object_rules']['object_classes']
                ?? $record->object_classes
                ?? ''
        );

        if ($objectClasses === []) {
            $objectClasses = ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];
        }

        $requiredAttributes = static::templateLines(
            $metadata['object_rules']['required_attributes']
                ?? $record->required_attributes
                ?? $rdnAttribute
        );

        $optionalAttributes = static::templateLines(
            $metadata['object_rules']['optional_attributes']
                ?? $record->optional_attributes
                ?? $record->additional_attributes
                ?? ''
        );

        $attributeMapping = static::templateKeyValueLines(
            $metadata['object_rules']['attribute_mapping']
                ?? $record->attribute_mapping
                ?? ''
        );

        $multiValueSeparator = $metadata['object_rules']['multi_value_separator']
            ?? $record->multi_value_separator
            ?? ';';

        $sampleRows = (int) (
            $metadata['safety_rules']['sample_rows']
                ?? $record->sample_rows
                ?? 3
        );

        if ($sampleRows <= 0) {
            $sampleRows = 3;
        }

        $attributeColumns = array_values(array_unique(array_filter(array_merge(
            [$rdnAttribute],
            $requiredAttributes,
            $optionalAttributes,
            array_keys($attributeMapping),
        ))));

        $attributeColumns = array_values(array_filter(
            $attributeColumns,
            fn ($attribute) => ! in_array($attribute, ['dn', 'action', 'objectClass'], true)
        ));

        $headers = array_values(array_unique(array_merge(
            ['action', 'dn'],
            $attributeColumns,
            ['objectClass']
        )));

        $rows = [];
        $rows[] = $headers;

        $objectClassValue = implode((string) $multiValueSeparator, $objectClasses);
        $action = match ((string) $operationMode) {
            'update' => 'update',
            'upsert' => 'upsert',
            'delete' => 'delete',
            default => 'create',
        };

        for ($i = 1; $i <= $sampleRows; $i++) {
            $number = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $rowData = [];

            foreach ($headers as $header) {
                $rowData[$header] = '';
            }

            $identifierValue = match ($rdnAttribute) {
                'cn' => 'test-group-'.$number,
                'ou' => 'test-ou-'.$number,
                default => 'test-user-'.$number,
            };

            $placeholderData = [
                'base_dn' => $baseDn,
                $rdnAttribute => $identifierValue,
                'uid' => $identifierValue,
                'cn' => $identifierValue,
                'ou' => $identifierValue,
            ];

            $dn = preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($placeholderData) {
                return (string) ($placeholderData[$matches[1]] ?? '');
            }, (string) $dnTemplate);

            $rowData['action'] = $action;
            $rowData['dn'] = $dn;
            $rowData['objectClass'] = $objectClassValue;

            foreach ($attributeColumns as $attribute) {
                if ($attribute === $rdnAttribute) {
                    $rowData[$attribute] = $identifierValue;
                    continue;
                }

                $rowData[$attribute] = match ($attribute) {
                    'uid' => 'test-user-'.$number,
                    'cn' => $rdnAttribute === 'cn' ? $identifierValue : 'Test User '.$number,
                    'sn' => 'User '.$number,
                    'givenName' => 'Test',
                    'displayName' => 'Test User '.$number,
                    'mail' => 'test.user'.$number.'@example.local',
                    'member' => 'uid=test-user-001,ou=people,'.$baseDn,
                    'description' => 'Generated from LDAP Import Template',
                    default => $attribute.'-'.$number,
                };
            }

            $rows[] = array_map(fn ($header) => $rowData[$header] ?? '', $headers);
        }

        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    public static function storeGeneratedImportTemplateFile(mixed $record): string
    {
        $csv = static::buildGenericImportTemplateCsvFromRecord($record);

        $safeName = str($record->name ?? 'ldap-import-template')
            ->slug()
            ->toString();

        $path = 'imports/templates/'.$safeName.'_'.now()->format('Ymd_His').'.csv';

        Storage::disk('local')->put($path, $csv);

        return $path;
    }


    public static function downloadGeneratedTemplateFresh(mixed $record)
    {
        $path = static::storeGeneratedImportTemplateFile($record);

        if (method_exists($record, 'forceFill')) {
            $record->forceFill([
                'output_path' => $path,
                'status' => 'generated',
            ])->save();
        }

        return response()->download(
            Storage::disk('local')->path($path),
            str($record->name ?? 'ldap-import-template')->slug().'.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportTemplates::route('/'),
            'create' => CreateImportTemplate::route('/create'),
            'view' => ViewImportTemplate::route('/{record}'),
            'edit' => EditImportTemplate::route('/{record}/edit'),
        ];
    }
}
