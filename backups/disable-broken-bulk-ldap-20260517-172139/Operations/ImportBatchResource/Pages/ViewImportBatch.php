<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use App\Services\Operations\LdapImportDependencyGraphService;
use App\Services\Operations\LdapImportApplyService;
use App\Services\Operations\LdapImportPreviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('previewAsLdif')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->action(function (): void {
                    $record = $this->getRecord();

                    try {
                        app(\App\Services\Operations\LdapImportPreviewService::class)
                            ->preview($record);

                        $summary = app(\App\Services\Operations\LdapImportLdifPlanService::class)
                            ->buildForBatch((int) $record->getKey());

                        app(\App\Services\Observability\UnifiedActivityLogger::class)
                            ->success(
                                module: 'operations.import',
                                action: 'preview_import',
                                message: 'LDAP import preview generated successfully.',
                                context: [
                                    'target_type' => 'import_batch',
                                    'target_id' => $record->getKey(),
                                    'target_label' => $record->name ?? null,
                                    'target_dn' => $record->base_dn ?? null,
                                    'ldap_connection_id' => $record->ldap_connection_id ?? $record->target_ldap_connection_id ?? null,
                                    'total' => $summary['total_rows'] ?? null,
                                    'success' => $summary['valid_rows'] ?? null,
                                    'failed' => $summary['failed_rows'] ?? null,
                                    'skipped' => $summary['skipped_rows'] ?? 0,
                                    'file_type' => $record->type ?? $record->file_type ?? null,
                                    'source' => 'filament',
                                ],
                            );

                        \Filament\Notifications\Notification::make()
                            ->title('Preview generated')
                            ->body('LDIF plan: Add '.($summary['add_rows'] ?? 0).' | Modify '.($summary['modify_rows'] ?? 0).' | Delete '.($summary['delete_rows'] ?? 0).' | Failed '.($summary['failed_rows'] ?? 0))
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record->getKey(),
                        ]));
                    } catch (\Throwable $exception) {
                        \Filament\Notifications\Notification::make()
                            ->title('Preview failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            \Filament\Actions\Action::make('downloadLdif')
                ->label('Download LDIF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return filled($record->preview_ldif_path ?? null);
                })
                ->action(function () {
                    $record = $this->getRecord();
                    $path = (string) ($record->preview_ldif_path ?? '');

                    if ($path === '' || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                        \Filament\Notifications\Notification::make()
                            ->title('LDIF file not found')
                            ->body('Run Preview first.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    return response()->download(
                        \Illuminate\Support\Facades\Storage::disk('local')->path($path),
                        'import-batch-'.$record->getKey().'-preview.ldif',
                        ['Content-Type' => 'text/plain']
                    );
                }),

            \Filament\Actions\Action::make('applyLdifImport')
                ->label('Apply')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Apply LDAP Import')
                ->modalDescription('Apply akan menjalankan generated LDIF ke target LDAP. Pastikan hasil Preview sudah benar.')
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    $status = (string) ($record->status ?? '');

                    if (! str_contains($status, 'ldif_preview_completed')) {
                        return false;
                    }

                    $failed = (int) (
                        $record->failed_rows
                        ?? $record->will_fail
                        ?? $record->fail_count
                        ?? 0
                    );

                    return $failed === 0;
                })
                ->action(function (): void {
                    $record = $this->getRecord();

                    try {
                        $summary = app(\App\Services\Operations\LdapImportLdifApplyService::class)
                            ->apply((int) $record->getKey());

                        $applyFailed = (int) ($summary['failed'] ?? 0);

                        app(\App\Services\Observability\UnifiedActivityLogger::class)
                            ->{$applyFailed > 0 ? 'warning' : 'success'}(
                                module: 'operations.import',
                                action: 'apply_import',
                                message: $applyFailed > 0
                                    ? 'LDAP import applied with issues.'
                                    : 'LDAP import applied successfully.',
                                context: [
                                    'target_type' => 'import_batch',
                                    'target_id' => $record->getKey(),
                                    'target_label' => $record->name ?? null,
                                    'target_dn' => $record->base_dn ?? null,
                                    'ldap_connection_id' => $record->ldap_connection_id ?? $record->target_ldap_connection_id ?? null,
                                    'total' => $summary['total'] ?? null,
                                    'success' => $summary['success'] ?? null,
                                    'failed' => $summary['failed'] ?? null,
                                    'skipped' => $summary['skipped'] ?? null,
                                    'add' => $summary['add'] ?? null,
                                    'modify' => $summary['modify'] ?? null,
                                    'delete' => $summary['delete'] ?? null,
                                    'file_type' => $record->type ?? $record->file_type ?? null,
                                    'source' => 'filament',
                                ],
                            );

                        if (($summary['failed'] ?? 0) > 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('LDAP import applied with issues')
                                ->body('Success: '.($summary['success'] ?? 0).' | Failed: '.($summary['failed'] ?? 0).' | Skipped: '.($summary['skipped'] ?? 0))
                                ->warning()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('LDAP import applied')
                                ->body('Success: '.($summary['success'] ?? 0).' | Failed: 0 | Skipped: '.($summary['skipped'] ?? 0))
                                ->success()
                                ->send();
                        }

                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record->getKey(),
                        ]));
                    } catch (\Throwable $exception) {
                        \Filament\Notifications\Notification::make()
                            ->title('Apply failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            \Filament\Actions\Action::make('rollbackImport')
                ->label('Rollback')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rollback LDAP Import')
                ->modalDescription('Rollback akan membatalkan entry yang pernah dibuat oleh batch import ini. Untuk update/delete, rollback penuh membutuhkan snapshot.')
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return in_array((string) ($record->status ?? ''), [
                        'success',
                        'applied',
                        'rollback_failed',
                        'rollback_completed_with_issues',
                    ], true);
                })
                ->action(function (): void {
                    $record = $this->getRecord();

                    try {
                        $summary = app(\App\Services\Operations\LdapImportRollbackService::class)
                            ->rollback((int) $record->getKey());

                        $rollbackFailed = (int) ($summary['failed'] ?? 0);

                        app(\App\Services\Observability\UnifiedActivityLogger::class)
                            ->{$rollbackFailed > 0 ? 'warning' : 'success'}(
                                module: 'operations.import',
                                action: 'rollback_import',
                                message: $rollbackFailed > 0
                                    ? 'LDAP import rollback completed with issues.'
                                    : 'LDAP import rollback completed successfully.',
                                context: [
                                    'target_type' => 'import_batch',
                                    'target_id' => $record->getKey(),
                                    'target_label' => $record->name ?? null,
                                    'target_dn' => $record->base_dn ?? null,
                                    'ldap_connection_id' => $record->ldap_connection_id ?? $record->target_ldap_connection_id ?? null,
                                    'total' => $summary['total'] ?? null,
                                    'success' => $summary['rolled_back'] ?? null,
                                    'failed' => $summary['failed'] ?? null,
                                    'skipped' => $summary['skipped'] ?? null,
                                    'file_type' => $record->type ?? $record->file_type ?? null,
                                    'source' => 'filament',
                                ],
                            );

                        \Filament\Notifications\Notification::make()
                            ->title('Rollback completed')
                            ->body('Rolled back '.($summary['rolled_back'] ?? 0).' | Failed '.($summary['failed'] ?? 0).' | Skipped '.($summary['skipped'] ?? 0))
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record->getKey(),
                        ]));
                    } catch (\Throwable $exception) {
                        \Filament\Notifications\Notification::make()
                            ->title('Rollback failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            \Filament\Actions\Action::make('backToImports')
                ->label('Back')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::getResource()::getUrl('index')),
        ];
    }
}
