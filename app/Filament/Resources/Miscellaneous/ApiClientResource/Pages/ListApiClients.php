<?php

namespace App\Filament\Resources\Miscellaneous\ApiClientResource\Pages;

use App\Filament\Resources\Miscellaneous\ApiClientResource;
use App\Models\Api\ApiClient;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_api_key')
                ->label('Generate API Key')
                ->icon('heroicon-o-key')
                ->color('primary')
                ->modalHeading('Generate Read-only API Key')
                ->modalDescription('API key ini hanya digunakan untuk membaca Users, Directory Objects, dan Schema Browser. Tidak ada create, update, delete LDAP, password change, import, export, atau LDAP modify.')
                ->form([
                    TextInput::make('name')
                        ->label('Client Name')
                        ->placeholder('Example: Portal Read Only Client')
                        ->required()
                        ->maxLength(255),

                    CheckboxList::make('scopes')
                        ->label('Allowed Scopes')
                        ->options([
                            'users:read' => 'users:read - Read LDAP Users',
                            'directory:read' => 'directory:read - Read Directory Objects',
                            'schema:read' => 'schema:read - Read Schema Browser',
                        ])
                        ->default([
                            'users:read',
                            'directory:read',
                            'schema:read',
                        ])
                        ->columns(1)
                        ->required(),

                    DateTimePicker::make('expires_at')
                        ->label('Expires At')
                        ->placeholder('Optional'),

                    Textarea::make('description')
                        ->label('Description')
                        ->placeholder('Optional description for this API client.')
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $plainKey = ApiClient::generatePlainKey();

                    $scopes = collect($data['scopes'] ?? [])
                        ->map(fn ($scope): string => trim((string) $scope))
                        ->filter()
                        ->values()
                        ->all();

                    $client = ApiClient::query()->create([
                        'name' => trim((string) ($data['name'] ?? 'Unnamed API Client')),
                        'key_prefix' => ApiClient::prefixFromPlainKey($plainKey),
                        'key_hash' => ApiClient::hashPlainKey($plainKey),
                        'scopes' => $scopes,
                        'is_active' => true,
                        'expires_at' => $data['expires_at'] ?? null,
                        'description' => $data['description'] ?? null,
                    ]);

                    Notification::make()
                        ->title('API key generated successfully')
                        ->body(
                            "Client: {$client->name}\n\n".
                            "Plain API key is shown only once. Copy and store it safely:\n\n".
                            $plainKey
                        )
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
