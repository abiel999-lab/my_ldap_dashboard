<?php

namespace App\Http\Middleware\Api;

use App\Models\Api\ApiClient;
use App\Models\Api\ApiRequestLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReadOnlyApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $scope = $this->requiredScope($request);
        $client = null;

        try {
            $plainKey = $this->extractApiKey($request);

            if (! $plainKey) {
                $response = $this->deny('API key is required.', 401);
                $this->writeLog($request, null, $scope, $response, $startedAt, 'Missing API key.');
                return $response;
            }

            $client = ApiClient::query()
                ->where('key_hash', ApiClient::hashPlainKey($plainKey))
                ->first();

            if (! $client) {
                $response = $this->deny('Invalid API key.', 401);
                $this->writeLog($request, null, $scope, $response, $startedAt, 'Invalid API key.');
                return $response;
            }

            if (! $client->is_active) {
                $response = $this->deny('API key is inactive.', 403);
                $this->writeLog($request, $client, $scope, $response, $startedAt, 'Inactive API key.');
                return $response;
            }

            if ($client->expires_at && now()->greaterThan($client->expires_at)) {
                $response = $this->deny('API key is expired.', 403);
                $this->writeLog($request, $client, $scope, $response, $startedAt, 'Expired API key.');
                return $response;
            }

            if ($scope && ! $client->hasScope($scope)) {
                $response = $this->deny('API key does not have required scope: '.$scope, 403);
                $this->writeLog($request, $client, $scope, $response, $startedAt, 'Forbidden scope.');
                return $response;
            }

            $client->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => $request->ip(),
            ])->save();

            $request->attributes->set('api_client', $client);
            $request->attributes->set('api_scope', $scope);

            $response = $next($request);

            $this->writeLog($request, $client, $scope, $response, $startedAt);

            return $response;
        } catch (Throwable $e) {
            $response = response()->json([
                'ok' => false,
                'message' => 'API request failed.',
                'error' => $e->getMessage(),
            ], 500);

            $this->writeLog($request, $client, $scope, $response, $startedAt, $e->getMessage());

            return $response;
        }
    }

    private function extractApiKey(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization', '');

        if (str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        $headerKey = $request->header('X-API-Key');

        return $headerKey ? trim((string) $headerKey) : null;
    }

    private function requiredScope(Request $request): ?string
    {
        $path = '/'.ltrim($request->path(), '/');

        return match (true) {
            str_starts_with($path, '/api/v1/users') => 'users:read',
            str_starts_with($path, '/api/v1/directory-objects') => 'directory:read',
            str_starts_with($path, '/api/v1/schema') => 'schema:read',
            default => null,
        };
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
        ], $status);
    }

    private function writeLog(
        Request $request,
        ?ApiClient $client,
        ?string $scope,
        Response $response,
        float $startedAt,
        ?string $error = null,
    ): void {
        try {
            ApiRequestLog::query()->create([
                'api_client_id' => $client?->id,
                'api_client_name' => $client?->name,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'scope' => $scope,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status_code' => $response->getStatusCode(),
                'ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 400,
                'request_query' => $request->query(),
                'response_summary' => [
                    'status_code' => $response->getStatusCode(),
                    'content_type' => $response->headers->get('Content-Type'),
                ],
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable) {
            // API logging must never break API response.
        }
    }
}
