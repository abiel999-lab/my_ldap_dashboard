<?php

namespace App\Filament\Resources\Directory\DirectoryObjectManagerResource\Pages;

use App\Filament\Resources\Directory\DirectoryObjectManagerResource;
use App\Jobs\Directory\GenericLdapEntryMutationJob;
use App\Jobs\Directory\SyncDirectoryObjectsJob;
use App\Support\Directory\LdapSchemaObjectClassHelper;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Throwable;

class ViewDirectoryObject extends ViewRecord
{
    protected static string $resource = DirectoryObjectManagerResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncThisObject')
                ->label('Sync This Object')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->queueSyncThisObject()),

            ActionGroup::make([
                Action::make('renameRdn')
                    ->label('Rename RDN')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->form([
                        TextInput::make('rdn_attribute')
                            ->label('RDN Attribute')
                            ->default(fn (): string => DirectoryObjectManagerResource::rdnAttribute((string) ($this->record->dn ?? '')))
                            ->required(),

                        TextInput::make('rdn_value')
                            ->label('New RDN Value')
                            ->required(),

                        Toggle::make('delete_old_rdn')
                            ->label('Delete old RDN value')
                            ->default(true),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('rename_rdn', $data)),

                Action::make('moveEntry')
                    ->label('Move Parent DN')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->form([
                        TextInput::make('new_parent_dn')
                            ->label('New Parent DN')
                            ->default(fn (): string => $this->parentDn((string) ($this->record->dn ?? '')))
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('move_entry', $data)),

                Action::make('addObjectClass')
                    ->label('Add ObjectClass')
                    ->icon('heroicon-o-cube')
                    ->color('primary')
                    ->form([
                        Select::make('object_class')
                            ->label('ObjectClass')
                            ->options(fn (): array => $this->objectClassOptions())
                            ->searchable()
                            ->preload()
                            ->required(),

                        KeyValue::make('must_attributes')
                            ->label('MUST Attributes')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->helperText('Isi hanya jika objectClass membutuhkan MUST attribute tambahan.'),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('add_objectclass', $data)),

                Action::make('removeObjectClass')
                    ->label('Remove ObjectClass')
                    ->icon('heroicon-o-cube-transparent')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('object_class')
                            ->label('ObjectClass')
                            ->options(fn (): array => $this->currentObjectClassOptions())
                            ->searchable()
                            ->preload()
                            ->required(),

                        TagsInput::make('remove_attributes')
                            ->label('Remove attributes first')
                            ->helperText('Jika objectClass masih dipakai attribute tertentu, masukkan attribute yang perlu dihapus dulu.'),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('remove_objectclass', $data)),

                Action::make('deleteObject')
                    ->label('Delete Object')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP object?')
                    ->modalDescription('Object akan dihapus dari LDAP lewat queue dan tercatat di Command Executions.')
                    ->action(fn () => $this->queueMutation('delete_entry', [])),
            ])
                ->label('Object Actions')
                ->icon('heroicon-o-command-line')
                ->color('primary'),
        ];
    }

    private function queueMutation(string $operation, array $payload = []): void
    {
        try {
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_detail_mutation_queued',
                'queued job: GenericLdapEntryMutationJob',
                [
                    'operation' => $operation,
                    'model_class' => get_class($this->record),
                    'record_id' => $this->record->id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'payload' => $payload,
                    'queue' => 'ldap',
                ]
            );

            GenericLdapEntryMutationJob::dispatch(
                get_class($this->record),
                (int) $this->record->id,
                $operation,
                $payload,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('LDAP operation queued')
                ->body('Operation: '.$operation.' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_detail_mutation_dispatch_failed',
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'record_id' => $this->record->id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'payload' => $payload,
                ]
            );

            Notification::make()
                ->title('LDAP operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function queueSyncThisObject(): void
    {
        try {
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_single_sync_queued',
                'queued job: SyncDirectoryObjectsJob',
                [
                    'operation' => 'sync_directory_object_connection',
                    'ldap_connection_id' => $this->record->ldap_connection_id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'queue' => 'ldap',
                ]
            );

            SyncDirectoryObjectsJob::dispatch(
                $this->record->ldap_connection_id ? (int) $this->record->ldap_connection_id : null,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('Directory object sync queued')
                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_single_sync_dispatch_failed',
                $e->getMessage(),
                [
                    'record_id' => $this->record->id ?? null,
                    'dn' => $this->record->dn ?? null,
                ]
            );

            Notification::make()
                ->title('Sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function objectClassOptions(): array
    {
        try {
            $options = app(LdapSchemaObjectClassHelper::class)
                ->objectClassOptions((int) ($this->record->ldap_connection_id ?? 0));

            if ($options !== []) {
                return $options;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'extensibleObject' => 'extensibleObject',
            'organizationalUnit' => 'organizationalUnit',
            'groupOfNames' => 'groupOfNames',
            'groupOfUniqueNames' => 'groupOfUniqueNames',
            'applicationProcess' => 'applicationProcess',
            'device' => 'device',
        ];
    }

    private function currentObjectClassOptions(): array
    {
        return collect(DirectoryObjectManagerResource::extractObjectClasses($this->record))
            ->mapWithKeys(fn ($value): array => [$value => $value])
            ->toArray();
    }

    private function parentDn(string $dn): string
    {
        if (! str_contains($dn, ',')) {
            return '';
        }

        return explode(',', $dn, 2)[1] ?? '';
    }
}
