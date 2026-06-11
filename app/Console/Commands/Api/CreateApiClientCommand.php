<?php

namespace App\Console\Commands\Api;

use App\Models\Api\ApiClient;
use Illuminate\Console\Command;

class CreateApiClientCommand extends Command
{
    protected $signature = 'iam:api-client-create
        {name : API client name}
        {--scopes=users:read,directory:read,schema:read : Comma separated scopes}
        {--expires= : Expiration datetime, example: 2026-12-31 23:59:59}
        {--description= : Description}
        {--write-to= : Optional file path to store generated plain API key}';

    protected $description = 'Create a read-only API client and generate one-time API key.';

    public function handle(): int
    {
        $plainKey = ApiClient::generatePlainKey();

        $scopes = collect(explode(',', (string) $this->option('scopes')))
            ->map(fn (string $scope): string => trim($scope))
            ->filter()
            ->values()
            ->all();

        $client = ApiClient::query()->create([
            'name' => (string) $this->argument('name'),
            'key_prefix' => ApiClient::prefixFromPlainKey($plainKey),
            'key_hash' => ApiClient::hashPlainKey($plainKey),
            'scopes' => $scopes,
            'is_active' => true,
            'expires_at' => $this->option('expires') ?: null,
            'description' => $this->option('description') ?: null,
        ]);

        if ($this->option('write-to')) {
            file_put_contents((string) $this->option('write-to'), $plainKey);
            @chmod((string) $this->option('write-to'), 0600);
        }

        $this->info('API client created.');
        $this->line('ID          : '.$client->id);
        $this->line('Name        : '.$client->name);
        $this->line('Key Prefix  : '.$client->key_prefix);
        $this->line('Scopes      : '.implode(', ', $scopes));
        $this->warn('Plain API key is shown only once. Store it safely.');
        $this->line('API_KEY='.$plainKey);

        return self::SUCCESS;
    }
}
