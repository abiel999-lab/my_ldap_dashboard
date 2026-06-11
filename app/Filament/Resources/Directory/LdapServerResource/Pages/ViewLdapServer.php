<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Models\Directory\LdapServer;
use App\Services\Directory\LdapServerProvisioningService;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewLdapServer extends ViewRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('testConnection')
                ->label('Test LDAP Bind')
                ->icon('heroicon-o-signal')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var LdapServer $record */
                    $record = $this->record;

                    try {
                        $result = app(LdapServerProvisioningService::class)->testConnection($record);

                        $record->forceFill([
                            'last_tested_at' => now(),
                            'last_test_status' => $result['ok'] ? 'success' : 'failed',
                            'status' => $result['ok'] ? 'online' : 'error',
                            'last_error' => $result['ok'] ? null : $result['message'],
                        ])->save();

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'test_ldap_bind',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'LDAP bind success' : 'LDAP bind failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    } catch (Throwable $exception) {
                        $this->logLdapServerActivity(
                            ok: false,
                            action: 'test_ldap_bind',
                            message: 'LDAP bind test failed: '.$exception->getMessage(),
                            extra: [
                                'error' => $exception->getMessage(),
                            ],
                        );

                        throw $exception;
                    }
                }),

            Action::make('registerConnection')
                ->label('Register to LDAP Connections')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $result = app(LdapServerProvisioningService::class)->registerAsLdapConnection($this->record);

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'register_ldap_connection',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'LDAP Connection registered' : 'Register failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    } catch (Throwable $exception) {
                        $this->logLdapServerActivity(
                            ok: false,
                            action: 'register_ldap_connection',
                            message: 'LDAP connection registration failed: '.$exception->getMessage(),
                            extra: [
                                'error' => $exception->getMessage(),
                            ],
                        );

                        throw $exception;
                    }
                }),

            ActionGroup::make([
                Action::make('applyKubernetes')
                    ->label('Apply Kubernetes Manifest')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Apply Kubernetes manifest?')
                    ->modalDescription('This will create or update Kubernetes Secret, PVC, Deployment, and Service for this LDAP server.')
                    ->visible(fn (): bool => in_array($this->record->provision_mode, ['kubernetes', 'k8s'], true))
                    ->action(function (): void {
                        /** @var LdapServer $record */
                        $record = $this->record;

                        app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($record);
                        $record = $record->refresh();

                        $result = app(LdapServerProvisioningService::class)->applyKubernetesManifest($record);

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'apply_kubernetes_manifest',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'Kubernetes manifest applied' : 'Kubernetes apply failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('checkKubernetes')
                    ->label('Check Kubernetes Status')
                    ->icon('heroicon-o-command-line')
                    ->visible(fn (): bool => in_array($this->record->provision_mode, ['kubernetes', 'k8s'], true))
                    ->action(function (): void {
                        $result = app(LdapServerProvisioningService::class)->checkKubernetesStatus($this->record);

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'check_kubernetes_status',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'Kubernetes status checked' : 'Kubernetes status failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('restartKubernetes')
                    ->label('Restart Kubernetes Deployment')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => in_array($this->record->provision_mode, ['kubernetes', 'k8s'], true))
                    ->action(function (): void {
                        $result = app(LdapServerProvisioningService::class)->restartKubernetesDeployment($this->record);

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'restart_kubernetes_deployment',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'Kubernetes deployment restarted' : 'Kubernetes restart failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('deleteKubernetes')
                    ->label('Delete Kubernetes Resources')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete generated Kubernetes resources?')
                    ->modalDescription('This deletes Kubernetes resources generated by this LDAP server manifest. PVCs may contain LDAP data.')
                    ->visible(fn (): bool => in_array($this->record->provision_mode, ['kubernetes', 'k8s'], true))
                    ->action(function (): void {
                        $result = app(LdapServerProvisioningService::class)->deleteKubernetesResources($this->record);

                        $this->logLdapServerActivity(
                            ok: (bool) $result['ok'],
                            action: 'delete_kubernetes_resources',
                            message: $result['message'],
                            extra: [
                                'result' => $result,
                            ],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'Kubernetes resources deleted' : 'Kubernetes delete failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),
            ])
                ->label('Kubernetes Actions')
                ->icon('heroicon-o-cloud')
                ->button(),

            ActionGroup::make([
                Action::make('refreshArtifacts')
                    ->label('Refresh Artifacts')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (): void {
                        try {
                            app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);

                            $this->logLdapServerActivity(
                                ok: true,
                                action: 'refresh_ldap_server_artifacts',
                                message: 'LDAP server artifacts refreshed.',
                            );

                            Notification::make()
                                ->title('Artifacts refreshed')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            $this->logLdapServerActivity(
                                ok: false,
                                action: 'refresh_ldap_server_artifacts',
                                message: 'LDAP server artifacts refresh failed: '.$exception->getMessage(),
                                extra: [
                                    'error' => $exception->getMessage(),
                                ],
                            );

                            throw $exception;
                        }
                    }),

                Action::make('startDocker')
                    ->label('Start Docker LDAP')
                    ->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->runDockerAction('start_docker_ldap', 'Docker LDAP started', 'Docker start failed', function (): array {
                            return app(LdapServerProvisioningService::class)->startDockerContainer($this->record);
                        });
                    }),

                Action::make('stopDocker')
                    ->label('Stop Docker LDAP')
                    ->icon('heroicon-o-stop')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->runDockerAction('stop_docker_ldap', 'Docker LDAP stopped', 'Docker stop failed', function (): array {
                            return app(LdapServerProvisioningService::class)->stopDockerContainer($this->record);
                        });
                    }),

                Action::make('restartDocker')
                    ->label('Restart Docker LDAP')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->runDockerAction('restart_docker_ldap', 'Docker LDAP restarted', 'Docker restart failed', function (): array {
                            return app(LdapServerProvisioningService::class)->restartDockerContainer($this->record);
                        });
                    }),

                Action::make('checkDocker')
                    ->label('Check Docker Status')
                    ->icon('heroicon-o-command-line')
                    ->action(function (): void {
                        $this->runDockerAction('check_docker_ldap_status', 'Docker status checked', 'Docker status failed', function (): array {
                            return app(LdapServerProvisioningService::class)->checkDockerContainer($this->record);
                        });
                    }),
            ])
                ->label('Docker Actions')
                ->visible(fn (): bool => (bool) env('LDAP_SERVER_ENABLE_DOCKER_ACTIONS', false))
                ->icon('heroicon-o-command-line')
                ->button(),
        ];
    }

    private function runDockerAction(
        string $action,
        string $successTitle,
        string $failedTitle,
        callable $callback
    ): void {
        try {
            $result = $callback();

            $this->logLdapServerActivity(
                ok: (bool) $result['ok'],
                action: $action,
                message: $result['message'],
                extra: [
                    'result' => $result,
                ],
            );

            Notification::make()
                ->title($result['ok'] ? $successTitle : $failedTitle)
                ->body($result['message'])
                ->{$result['ok'] ? 'success' : 'danger'}()
                ->send();
        } catch (Throwable $exception) {
            $this->logLdapServerActivity(
                ok: false,
                action: $action,
                message: $failedTitle.': '.$exception->getMessage(),
                extra: [
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    private function logLdapServerActivity(
        bool $ok,
        string $action,
        string $message,
        array $extra = []
    ): void {
        /** @var LdapServer|null $record */
        $record = $this->record;

        $context = array_merge([
            'operation_type' => 'ldap_server',
            'event' => $action,
            'target_type' => 'ldap_server',
            'target_id' => $record?->getKey(),
            'target_label' => $record?->name ?? $record?->server_name ?? null,
            'host' => $record?->host ?? $record?->hostname ?? $record?->url ?? null,
            'port' => $record?->port ?? null,
            'status' => $record?->status ?? null,
            'source' => 'filament',
            'total' => 1,
            'success' => $ok ? 1 : 0,
            'failed' => $ok ? 0 : 1,
            'skipped' => 0,
        ], $extra);

        $logger = app(UnifiedActivityLogger::class);

        if ($ok) {
            $logger->success('directory.ldap_servers', $action, $message, $context);
            return;
        }

        $logger->failed('directory.ldap_servers', $action, $message, $context);
    }
}
