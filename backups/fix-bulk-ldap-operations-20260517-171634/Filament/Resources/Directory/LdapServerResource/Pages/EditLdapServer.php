<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Services\Directory\LdapServerProvisioningService;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditLdapServer extends EditRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return LdapServerResource::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        try {
            app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);

            $this->logLdapServerActivity(
                status: 'success',
                action: 'update_ldap_server',
                message: 'LDAP server updated and generated artifacts refreshed.',
                context: [
                    'artifact_refresh' => true,
                ],
            );
        } catch (Throwable $exception) {
            $this->logLdapServerActivity(
                status: 'failed',
                action: 'update_ldap_server',
                message: 'LDAP server updated but artifact refresh failed: '.$exception->getMessage(),
                context: [
                    'artifact_refresh' => false,
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->logLdapServerActivity(
                        status: 'success',
                        action: 'delete_ldap_server_requested',
                        message: 'LDAP server delete requested.',
                    );
                })
                ->after(function (): void {
                    app(UnifiedActivityLogger::class)->success(
                        module: 'directory.ldap_servers',
                        action: 'delete_ldap_server',
                        message: 'LDAP server deleted successfully.',
                        context: [
                            'operation_type' => 'ldap_server',
                            'event' => 'delete_ldap_server',
                            'target_type' => 'ldap_server',
                            'target_id' => $this->record?->getKey(),
                            'target_label' => $this->record?->name ?? $this->record?->server_name ?? null,
                            'source' => 'filament',
                            'total' => 1,
                            'success' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    );
                }),
        ];
    }

    private function logLdapServerActivity(
        string $status,
        string $action,
        string $message,
        array $context = []
    ): void {
        $record = $this->record;

        $context = array_merge([
            'operation_type' => 'ldap_server',
            'event' => $action,
            'target_type' => 'ldap_server',
            'target_id' => $record?->getKey(),
            'target_label' => $record?->name ?? $record?->server_name ?? null,
            'host' => $record?->host ?? $record?->hostname ?? $record?->url ?? null,
            'port' => $record?->port ?? null,
            'source' => 'filament',
            'total' => 1,
            'success' => $status === 'success' ? 1 : 0,
            'failed' => $status === 'failed' ? 1 : 0,
            'skipped' => 0,
        ], $context);

        $logger = app(UnifiedActivityLogger::class);

        if ($status === 'success') {
            $logger->success('directory.ldap_servers', $action, $message, $context);
            return;
        }

        $logger->failed('directory.ldap_servers', $action, $message, $context);
    }
}
