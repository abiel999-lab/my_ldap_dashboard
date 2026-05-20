<?php

namespace App\Filament\Pages\Directory;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource;
use App\Jobs\Directory\BulkDeleteLdapEntriesJob;
use App\Models\Directory\LdapDirectoryEntry;
use App\Support\Directory\DynamicEntryTypeRegistry;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\HtmlString;
use Throwable;

class DynamicLdapEntries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationLabel = 'Dynamic LDAP Entries';

    protected static ?string $title = 'Dynamic LDAP Entries';

    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?int $navigationSort = 9999;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'directory/dynamic-entries/{entryType?}';

    protected string $view = 'filament.pages.directory.dynamic-ldap-entries';

    public ?string $entryType = null;

    public function mount(?string $entryType = null): void
    {
        $this->entryType = $entryType;
    }

    public function getTitle(): string
    {
        $type = $this->currentType();

        if (! $type) {
            return 'Dynamic LDAP Entries';
        }

        return (string) ($type['label'] ?? 'Dynamic LDAP Entries');
    }

    public function getBreadcrumbs(): array
    {
        return [
            url('/admin') => 'Dashboard',
            '#' => 'Directory Management',
            url('/admin/directory/dynamic-entries/'.($this->entryType ?? '')) => $this->getTitle(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
            ->columns($this->tableColumns())
            ->filters([
                SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->relationship('ldapConnection', 'name')
                    ->visible(fn (): bool => $this->hasColumn('ldap_connection_id')),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make()
                    ->url(function ($record): ?string {
                        if (class_exists(LdapDirectoryEntryResource::class)) {
                            try {
                                return LdapDirectoryEntryResource::getUrl('view', [
                                    'record' => $record,
                                ]);
                            } catch (Throwable) {
                                return null;
                            }
                        }

                        return null;
                    }),

                \Filament\Actions\Action::make('deleteFromLdap')
                    ->label('Delete LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP entry?')
                    ->modalDescription('Entry akan dihapus dari LDAP lewat queue. Semua hasil masuk Command Executions.')
                    ->action(function ($record): void {
                        try {
                            $execution = SafeCommandExecutionLogger::createQueued(
                                'dynamic_ldap_entry_delete_queued',
                                'queued job: BulkDeleteLdapEntriesJob',
                                [
                                    'operation' => 'delete_dynamic_ldap_entry',
                                    'entry_type' => $this->entryType,
                                    'model_class' => get_class($record),
                                    'record_id' => $record->id,
                                    'dn' => $record->dn ?? null,
                                    'queue' => 'ldap',
                                ]
                            );

                            BulkDeleteLdapEntriesJob::dispatch(
                                get_class($record),
                                [$record->id],
                                SafeCommandExecutionLogger::id($execution),
                                $this->getTitle()
                            );

                            Notification::make()
                                ->title('LDAP delete queued')
                                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            SafeCommandExecutionLogger::createFailed(
                                'dynamic_ldap_entry_delete_dispatch_failed',
                                $e->getMessage(),
                                [
                                    'entry_type' => $this->entryType,
                                    'record_id' => $record->id ?? null,
                                    'dn' => $record->dn ?? null,
                                ]
                            );

                            Notification::make()
                                ->title('LDAP delete failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('bulkDeleteFromLdap')
                        ->label('Delete Selected From LDAP')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected LDAP entries?')
                        ->modalDescription('Semua entry terpilih akan dihapus lewat queue. Hasil detail masuk Command Executions.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records): void {
                            try {
                                $ids = $records->pluck('id')->values()->all();
                                $first = $records->first();

                                if (! $first) {
                                    throw new \RuntimeException('No selected records.');
                                }

                                $execution = SafeCommandExecutionLogger::createQueued(
                                    'dynamic_ldap_entries_bulk_delete_queued',
                                    'queued job: BulkDeleteLdapEntriesJob',
                                    [
                                        'operation' => 'bulk_delete_dynamic_ldap_entries',
                                        'entry_type' => $this->entryType,
                                        'model_class' => get_class($first),
                                        'record_count' => count($ids),
                                        'record_ids' => $ids,
                                        'queue' => 'ldap',
                                    ]
                                );

                                BulkDeleteLdapEntriesJob::dispatch(
                                    get_class($first),
                                    $ids,
                                    SafeCommandExecutionLogger::id($execution),
                                    $this->getTitle()
                                );

                                Notification::make()
                                    ->title('Bulk LDAP delete queued')
                                    ->body('Total: '.count($ids).' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                    ->success()
                                    ->send();
                            } catch (Throwable $e) {
                                SafeCommandExecutionLogger::createFailed(
                                    'dynamic_ldap_entries_bulk_delete_dispatch_failed',
                                    $e->getMessage(),
                                    [
                                        'entry_type' => $this->entryType,
                                    ]
                                );

                                Notification::make()
                                    ->title('Bulk LDAP delete failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->defaultSort($this->hasColumn('id') ? 'id' : null, 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public function infolist(Schema $schema): Schema
    {
        $type = $this->currentType();

        return $schema
            ->components([
                Section::make('Dynamic Entry Type')
                    ->description('Menu ini dibuat otomatis dari Entry Type Registry.')
                    ->schema([
                        \Filament\Schemas\Components\TextEntry::make('entry_type_label')
                            ->label('Label')
                            ->state($type['label'] ?? 'N/A'),

                        \Filament\Schemas\Components\TextEntry::make('entry_type_key')
                            ->label('Key')
                            ->state($type['key'] ?? 'N/A'),

                        \Filament\Schemas\Components\TextEntry::make('base_dn')
                            ->label('Base DN')
                            ->state($type['base_dn'] ?? 'N/A'),

                        \Filament\Schemas\Components\TextEntry::make('ldap_filter')
                            ->label('LDAP Filter')
                            ->state($type['ldap_filter'] ?? 'N/A'),

                        \Filament\Schemas\Components\TextEntry::make('object_class')
                            ->label('ObjectClass')
                            ->state($type['object_class'] ?? 'N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openEntryTypeRegistry')
                ->label('Entry Type Registry')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->url(url('/admin/directory/ldap-entry-type-rules')),

            Action::make('openDirectoryExplorer')
                ->label('Directory Explorer')
                ->icon('heroicon-o-folder')
                ->color('gray')
                ->url(url('/admin/directory/ldap-directory-entries')),
        ];
    }

    private function tableQuery(): Builder
    {
        $query = LdapDirectoryEntry::query();

        if ($this->hasColumn('status')) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', [
                        'missing_from_ldap',
                        'deleted_from_ldap',
                    ]);
            });
        }

        if (! $this->entryType) {
            return $query->whereRaw('1 = 0');
        }

        return app(DynamicEntryTypeRegistry::class)
            ->filterDirectoryQuery($query, $this->entryType);
    }

    private function tableColumns(): array
    {
        $columns = [];

        if ($this->hasColumn('id')) {
            $columns[] = TextColumn::make('id')
                ->label('ID')
                ->sortable();
        }

        foreach ([
            'rdn' => 'RDN',
            'dn' => 'DN',
            'cn' => 'CN',
            'uid' => 'UID',
            'ou' => 'OU',
            'name' => 'Name',
            'entry_type' => 'Type',
            'type' => 'Type',
            'ldapConnection.name' => 'Connection',
            'status' => 'Status',
            'last_seen_at' => 'Last Seen',
            'updated_at' => 'Updated',
        ] as $column => $label) {
            if (str_contains($column, '.') || $this->hasColumn($column)) {
                $textColumn = TextColumn::make($column)
                    ->label($label)
                    ->searchable(! str_contains($column, '.'))
                    ->sortable(! str_contains($column, '.'))
                    ->wrap();

                if ($column === 'status') {
                    $textColumn->badge();
                }

                if (in_array($column, ['last_seen_at', 'updated_at'], true)) {
                    $textColumn->dateTime('M j, Y H:i:s');
                }

                $columns[] = $textColumn;
            }
        }

        if ($columns === []) {
            $columns[] = TextColumn::make('id')->label('ID');
        }

        return $columns;
    }

    private function currentType(): ?array
    {
        if (! $this->entryType) {
            return null;
        }

        return app(DynamicEntryTypeRegistry::class)->findType($this->entryType);
    }

    private function hasColumn(string $column): bool
    {
        try {
            return DatabaseSchema::hasColumn((new LdapDirectoryEntry())->getTable(), $column);
        } catch (Throwable) {
            return false;
        }
    }
}
