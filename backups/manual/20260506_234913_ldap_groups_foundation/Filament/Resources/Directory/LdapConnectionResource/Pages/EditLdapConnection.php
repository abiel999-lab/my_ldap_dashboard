<?php

namespace App\Filament\Resources\Directory\LdapConnectionResource\Pages;

use App\Filament\Resources\Directory\LdapConnectionResource;
use App\Services\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditLdapConnection extends EditRecord
{
    protected static string $resource = LdapConnectionResource::class;

    protected ?array $oldDataForAudit = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            DeleteAction::make()
                ->before(function (): void {
                    app(AuditLogger::class)->logModelAction(
                        module: 'directory.ldap_connections',
                        action: 'delete',
                        status: 'success',
                        target: $this->record,
                        before: $this->record->toArray(),
                        after: null,
                    );
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->oldDataForAudit = $this->record->toArray();

        if (Auth::check()) {
            $data['updated_by'] = Auth::id();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->logModelAction(
            module: 'directory.ldap_connections',
            action: 'update',
            status: 'success',
            target: $this->record,
            before: $this->oldDataForAudit,
            after: $this->record->fresh()?->toArray(),
        );
    }
}
