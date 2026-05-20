<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use App\Filament\Resources\Operations\ImportTemplateResource;
use App\Services\Operations\SmartImportBatchResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createSmartImport')
                ->label('New LDAP Import')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('Create Smart LDAP Import')
                ->modalWidth('4xl')
                ->schema([
                    \Filament\Schemas\Components\Section::make('1. Import Source')
                        ->description('Pilih target LDAP, template, dan upload file. Jika template dipilih, aturan DN, objectClass, attribute mapping, dan safety otomatis dipakai.')
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('name')
                                ->label('Import Name')
                                ->placeholder('Auto generated if empty'),

                            \Filament\Forms\Components\Select::make('ldap_connection_id')
                                ->label('Target LDAP')
                                ->options(fn (): array => $this->ldapConnectionOptions())
                                ->searchable()
                                ->preload()
                                ->required(),

                            \Filament\Forms\Components\Select::make('import_template_id')
                                ->label('Use Template')
                                ->options(fn (): array => $this->importTemplateOptions())
                                ->searchable()
                                ->preload()
                                ->placeholder('No template / manual import')
                                ->helperText('Disarankan pilih template agar mapping otomatis.'),

                            \Filament\Forms\Components\Select::make('import_type')
                                ->label('File Type')
                                ->options([
                                    'csv' => 'CSV',
                                    'ldif' => 'LDIF',
                                    'json' => 'JSON',
                                ])
                                ->default('csv')
                                ->required(),

                            \Filament\Forms\Components\FileUpload::make('upload_file')
                                ->label('Upload CSV / LDIF / JSON')
                                ->disk('local')
                                ->directory('imports/uploads')
                                ->preserveFilenames()
                                ->required()
                                ->columnSpanFull(),

                            \Filament\Forms\Components\TextInput::make('original_filename')
                                ->label('Original Filename')
                                ->placeholder('Optional, auto-filled if empty'),
                        ])
                        ->columns(2),

                    \Filament\Schemas\Components\Section::make('2. Advanced Manual Overrides')
                        ->description('Tidak perlu diisi jika memakai template. Dipakai hanya untuk import manual tanpa template.')
                        ->collapsed()
                        ->schema([
                            \Filament\Forms\Components\Select::make('operation_mode')
                                ->label('Operation')
                                ->options([
                                    'create' => 'Create only',
                                    'update' => 'Update only',
                                    'upsert' => 'Upsert',
                                    'delete' => 'Delete',
                                ])
                                ->default('create'),

                            \Filament\Forms\Components\TextInput::make('base_dn')
                                ->label('Base DN')
                                ->placeholder('dc=petra,dc=ac,dc=id'),

                            \Filament\Forms\Components\TextInput::make('target_parent_dn')
                                ->label('Target Parent DN / OU Path')
                                ->placeholder('ou=people,{base_dn}'),

                            \Filament\Forms\Components\TextInput::make('identifier_attribute')
                                ->label('Identifier / RDN Attribute')
                                ->default('uid')
                                ->placeholder('uid / cn / ou'),

                            \Filament\Forms\Components\TextInput::make('dn_template')
                                ->label('DN Template')
                                ->placeholder('uid={uid},ou=people,{base_dn}'),

                            \Filament\Forms\Components\Select::make('if_target_exists')
                                ->label('If Target Exists')
                                ->options([
                                    'skip' => 'Skip existing target',
                                    'update' => 'Update existing target',
                                    'fail' => 'Fail if target exists',
                                ])
                                ->default('skip'),
                        ])
                        ->columns(2),

                    \Filament\Schemas\Components\Section::make('3. Safety')
                        ->description('Default tetap aman: preview dulu sebelum apply.')
                        ->collapsed()
                        ->schema([
                            \Filament\Forms\Components\Toggle::make('safe_mode')
                                ->label('Safe Mode')
                                ->default(true),

                            \Filament\Forms\Components\Toggle::make('preview_only')
                                ->label('Preview First')
                                ->default(true),

                            \Filament\Forms\Components\Toggle::make('allow_destructive_operation')
                                ->label('Allow Destructive Operation')
                                ->default(false),
                        ])
                        ->columns(3),
                ])
                ->action(function (array $data): void {
                    try {
                        $id = app(SmartImportBatchResolver::class)->createImportBatch($data);

                        Notification::make()
                            ->title('LDAP import created')
                            ->body('Import batch created. Generate preview before applying.')
                            ->success()
                            ->send();

                        $this->redirect(ImportBatchResource::getUrl('view', [
                            'record' => $id,
                        ]));
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Failed to create LDAP import')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('openImportTemplates')
                ->label('Templates')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->url(fn (): string => ImportTemplateResource::getUrl('index')),

        ];
    }

    private function ldapConnectionOptions(): array
    {
        if (! Schema::hasTable('ldap_connections')) {
            return [];
        }

        return DB::table('ldap_connections')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    private function importTemplateOptions(): array
    {
        if (! Schema::hasTable('import_templates')) {
            return [];
        }

        return DB::table('import_templates')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }
}
