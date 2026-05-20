<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\EditImportTemplate;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\ListImportTemplates;
use App\Filament\Resources\Operations\ImportTemplateResource\Pages\ViewImportTemplate;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\ImportTemplate;
use App\Services\Operations\ImportTemplateGeneratorService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportTemplateResource extends Resource
{
    protected static ?string $model = ImportTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationLabel = 'Import Template Maker';

    protected static ?string $modelLabel = 'Import Template';

    protected static ?string $pluralModelLabel = 'Import Template Maker';

    protected static ?string $navigationGroup = '2. Operations';

    protected static ?int $navigationSort = 21;

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
                ->options(fn (): array => LdapConnection::query()
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray())
                ->searchable()
                ->preload()
                ->helperText('Choose LDAP connection so future schema-aware templates can match the correct server.'),

            Select::make('template_purpose')
                ->label('Template Purpose')
                ->options([
                    'create' => 'Create',
                    'update' => 'Edit / Update',
                    'delete' => 'Delete',
                ])
                ->default('create')
                ->required(),

            Select::make('entry_type')
                ->label('Entry Type')
                ->options([
                    'user' => 'User',
                    'group' => 'Group',
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
                ->default('dc=petra,dc=ac,dc=id')
                ->required(),

            TextInput::make('target_ou')
                ->label('Target OU')
                ->default('people')
                ->helperText('Example: people. Leave empty if DN should be directly under Base DN.'),

            TextInput::make('rdn_attribute')
                ->label('RDN / Identifier Attribute')
                ->default('uid')
                ->required()
                ->helperText('For users usually uid. For groups usually cn. For OU usually ou.'),

            Textarea::make('object_classes_text')
                ->label('Object Classes')
                ->default("top\nperson\norganizationalPerson\ninetOrgPerson")
                ->helperText('One per line. You may add custom objectClass like petraPerson.')
                ->afterStateHydrated(function (Textarea $component, $state, ?ImportTemplate $record): void {
                    if (! $record) {
                        return;
                    }

                    $component->state(implode("\n", $record->object_classes ?? []));
                })
                ->dehydrated(false)
                ->rows(6),

            Textarea::make('attributes_text')
                ->label('Additional Custom Attributes')
                ->helperText('One per line. Example: petraNrp, petraAffiliation, petraFaculty.')
                ->afterStateHydrated(function (Textarea $component, $state, ?ImportTemplate $record): void {
                    if (! $record) {
                        return;
                    }

                    $component->state(implode("\n", $record->attributes ?? []));
                })
                ->dehydrated(false)
                ->rows(6),

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
                    ->limit(40),

                BadgeColumn::make('template_purpose')
                    ->label('Purpose')
                    ->colors([
                        'success' => 'create',
                        'warning' => 'update',
                        'danger' => 'delete',
                    ]),

                BadgeColumn::make('file_format')
                    ->label('Format')
                    ->colors([
                        'info' => 'csv',
                        'gray' => 'ldif',
                        'success' => 'json',
                    ]),

                TextColumn::make('entry_type')
                    ->label('Entry')
                    ->badge(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'generated',
                        'danger' => 'failed',
                    ]),

                TextColumn::make('output_filename')
                    ->label('Output')
                    ->limit(32)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('generateTemplate')
                    ->label('Generate')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('success')
                    ->action(function (ImportTemplate $record): void {
                        $result = app(ImportTemplateGeneratorService::class)->generate($record);

                        Notification::make()
                            ->title(($result['ok'] ?? false) ? 'Template generated' : 'Generate failed')
                            ->body($result['message'] ?? 'Template generation finished.')
                            ->color(($result['ok'] ?? false) ? 'success' : 'danger')
                            ->send();
                    }),

                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (ImportTemplate $record): ?string => $record->download_url)
                    ->openUrlInNewTab(false)
                    ->visible(fn (ImportTemplate $record): bool => filled($record->output_path)),

                ViewAction::make()
                    ->label('View'),

                EditAction::make()
                    ->label('Edit'),
            ]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        return static::normalizeFormData($data);
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        return static::normalizeFormData($data);
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
