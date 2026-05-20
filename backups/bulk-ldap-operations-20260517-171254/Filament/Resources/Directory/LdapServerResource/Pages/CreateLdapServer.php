<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Services\Directory\LdapServerProvisioningService;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateLdapServer extends CreateRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LdapServerResource::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
    {
        try {
            app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);

            $this->logLdapServerActivity(
                status: 'success',
                action: 'create_ldap_server',
                message: 'LDAP server created and generated artifacts refreshed.',
                context: [
                    'artifact_refresh' => true,
                ],
            );
        } catch (Throwable $exception) {
            $this->logLdapServerActivity(
                status: 'failed',
                action: 'create_ldap_server',
                message: 'LDAP server created but artifact refresh failed: '.$exception->getMessage(),
                context: [
                    'artifact_refresh' => false,
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
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
