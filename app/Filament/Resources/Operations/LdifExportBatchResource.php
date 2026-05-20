<?php

namespace App\Filament\Resources\Operations;

use Throwable;

use Illuminate\Support\Facades\Storage;

use Illuminate\Database\Eloquent\Collection;

use Filament\Actions\BulkAction;

use App\Filament\Resources\Operations\LdifExportBatchResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdifExportBatch;
use App\Services\Operations\LdifExportDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LdifExportBatchResource extends Resource
{
    protected static ?string $model = LdifExportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'LDIF Exports';

    protected static ?string $modelLabel = 'LDIF Export';

    protected static ?string $pluralModelLabel = 'LDIF Exports';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Source')
                    ->schema([
                        TextInput::make('name')
                            ->label('Export Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => LdapConnection::query()
                                ->active()
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (LdapConnection $connection): array => [
                                    $connection->id => $connection->name.' | '.$connection->host.':'.$connection->port,
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->maxLength(1000)
                            ->default(fn (): ?string => LdapConnection::query()->active()->where('is_default', true)->value('base_dn')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Target')
                    ->schema([
                        Select::make('export_scope')
                            ->label('Export What?')
                            ->options([
                                'full' => 'Full Base DN',
                                'ou' => 'Specific OU',
                                'cn' => 'Specific CN',
                                'uid' => 'Specific UID',
                                'custom_dn' => 'Custom DN',
                            ])
                            ->default('full')
                            ->required()
                            ->live(),

                        Select::make('search_scope')
                            ->label('Search Scope')
                            ->options([
                                'base' => 'Base only',
                                'one' => 'One level',
                                'sub' => 'Full subtree',
                            ])
                            ->default('sub')
                            ->required(),

                        TextInput::make('target_rdn_attribute')
                            ->label('RDN Attribute')
                            ->placeholder('ou / cn / uid')
                            ->maxLength(100),

                        TextInput::make('target_rdn_value')
                            ->label('RDN Value')
                            ->placeholder('people / admin / abiel')
                            ->maxLength(255),

                        Textarea::make('custom_target_dn')
                            ->label('Custom Target DN')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->default('(objectClass=*)')
                            ->maxLength(500),

                        TextInput::make('attributes')
                            ->label('Attributes')
                            ->placeholder('* or dn cn uid mail objectClass')
                            ->maxLength(1000),

                        TextInput::make('size_limit')
                            ->label('Size Limit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->default(500)
                            ->required(),

                        Select::make('status')
                            ->label('Status')
                            ->options(['draft' => 'Draft'])
                            ->default('draft')
                            ->required()
                            ->hidden(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Export Target')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('export_scope')->label('Export Scope'),
                        TextEntry::make('search_scope')->label('Search Scope'),
                        TextEntry::make('base_dn')->label('Base DN')->columnSpanFull(),
                        TextEntry::make('display_target')->label('Effective Target DN')->columnSpanFull(),
                        TextEntry::make('filter')->label('Filter'),
                        TextEntry::make('attributes')->label('Attributes')->placeholder('*'),
                        TextEntry::make('size_limit')->label('Size Limit'),
                    ])
                    ->columns(2),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('preview_mode')->label('Preview Mode')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                    ])
                    ->columns(3),

                Section::make('Output')
                    ->schema([
                        TextEntry::make('output_path')->label('Output Path')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_output_size')->label('Output Size'),
                        TextEntry::make('output_hash')->label('SHA256')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('message')->label('Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('ldif_content_preview')
                            ->label('LDIF Content Preview')
                            ->state(fn (LdifExportBatch $record): string => $record->readOutputContent(60000))
                            ->placeholder('No LDIF content available.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Logs / Links')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('command_execution_id')->label('Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ldapConnection'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Name')->weight('semibold')->searchable()->sortable()->limit(40),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('ldapConnection.name')->label('LDAP')->placeholder('Default/Unknown')->limit(24),
                TextColumn::make('export_scope')->label('Scope')->badge(),
                TextColumn::make('search_scope')->label('Search')->badge(),
                TextColumn::make('display_target')->label('Target DN')->searchable()->limit(55),
                TextColumn::make('filter')->label('Filter')->limit(30),
                TextColumn::make('display_output_size')->label('Size'),
                TextColumn::make('operation_job_id')->label('Job')->placeholder('N/A'),
                TextColumn::make('command_execution_id')->label('Cmd')->placeholder('N/A'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->options(fn (): array => LdapConnection::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])

            ->bulkActions([
                BulkAction::make('safeBulkDelete')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected records?')
                    ->modalDescription('Only draft, success, failed, partial_success, and cancelled records will be deleted. Queued/running records are protected.')
                    ->action(function (Collection $records): void {
                        $protectedStatuses = ['queued', 'running', 'processing'];
                        $allowedStatuses = ['draft', 'success', 'failed', 'partial_success', 'cancelled'];

                        $blocked = $records->filter(fn ($record): bool => in_array((string) $record->status, $protectedStatuses, true));
                        $deletable = $records->filter(fn ($record): bool => in_array((string) $record->status, $allowedStatuses, true));

                        $deleted = 0;
                        $failed = 0;

                        foreach ($deletable as $record) {
                            try {
                                
                                if (filled($record->output_path ?? null) && Storage::disk('local')->exists((string) $record->output_path)) {
                                    Storage::disk('local')->delete((string) $record->output_path);
                                }

                                if (filled($record->file_path ?? null) && Storage::disk('local')->exists((string) $record->file_path)) {
                                    Storage::disk('local')->delete((string) $record->file_path);
                                }
                                $record->delete();
                                $deleted++;
                            } catch (Throwable $exception) {
                                $failed++;
                            }
                        }

                        if ($deleted > 0) {
                            Notification::make()
                                ->title('Bulk delete completed')
                                ->body($deleted.' LDIF export batches deleted successfully.')
                                ->success()
                                ->send();
                        }

                        if ($blocked->count() > 0) {
                            Notification::make()
                                ->title('Some records were not deleted')
                                ->body($blocked->count().' selected records are queued/running/processing and were protected.')
                                ->warning()
                                ->send();
                        }

                        if ($failed > 0) {
                            Notification::make()
                                ->title('Some records failed to delete')
                                ->body($failed.' records could not be deleted. Check Laravel logs for details.')
                                ->danger()
                                ->send();
                        }

                        if ($deleted === 0 && $blocked->count() === 0 && $failed === 0) {
                            Notification::make()
                                ->title('Nothing deleted')
                                ->body('No selected records matched the safe delete statuses.')
                                ->warning()
                                ->send();
                        }
                    }),
            ])

            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (LdifExportBatch $record): bool => in_array($record->status, ['draft', 'failed'], true)),

                Action::make('queueExport')
                    ->label('Queue Export')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (LdifExportBatch $record): bool => in_array($record->status, ['draft', 'failed'], true))
                    ->requiresConfirmation()
                    ->action(function (LdifExportBatch $record): void {
                        $result = app(LdifExportDispatcher::class)->queueExport($record);

                        Notification::make()
                            ->title($result['ok'] ? 'LDIF export queued' : 'Failed to queue LDIF export')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdifExportBatches::route('/'),
            'view' => Pages\ViewLdifExportBatch::route('/{record}'),
            'edit' => Pages\EditLdifExportBatch::route('/{record}/edit'),
        ];
    }
}
