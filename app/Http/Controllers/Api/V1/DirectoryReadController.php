<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DirectoryReadController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => 'Petra LDAP Dashboard Read-only API',
            'mode' => 'read-only',
            'version' => 'v1',
            'time' => now()->toISOString(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $page = $this->page($request);
        $connectionId = (int) $request->query('connection_id', 2);
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('ldap_user_entries')
            ->where('ldap_connection_id', $connectionId)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['missing_from_ldap', 'deleted_from_ldap', 'missing', 'deleted']);
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                foreach (['uid', 'dn', 'mail', 'name', 'cn', 'attributes'] as $column) {
                    if (Schema::hasColumn('ldap_user_entries', $column)) {
                        $q->orWhere($column, 'like', '%'.$search.'%');
                    }
                }
            });
        }

        $paginator = $query
            ->orderByDesc('last_synced_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn ($row): array => $this->formatUser($row))
            ->values();

        return response()->json([
            'ok' => true,
            'meta' => $this->paginationMeta($paginator, [
                'connection_id' => $connectionId,
                'search' => $search ?: null,
            ]),
            'data' => $items,
        ]);
    }

    public function user(string $uid): JsonResponse
    {
        $row = DB::table('ldap_user_entries')
            ->where('ldap_connection_id', 2)
            ->where(function ($q) use ($uid): void {
                $q->where('uid', $uid)
                    ->orWhere('mail', $uid)
                    ->orWhere('dn', $uid);
            })
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['missing_from_ldap', 'deleted_from_ldap', 'missing', 'deleted']);
            })
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->formatUser($row, includeAttributes: true),
        ]);
    }

    public function directoryObjects(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $page = $this->page($request);
        $connectionId = (int) $request->query('connection_id', 2);
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('ldap_directory_entries')
            ->where('ldap_connection_id', $connectionId)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['missing_from_ldap', 'deleted_from_ldap', 'missing', 'deleted']);
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                foreach (['dn', 'rdn', 'object_classes', 'attributes'] as $column) {
                    if (Schema::hasColumn('ldap_directory_entries', $column)) {
                        $q->orWhere($column, 'like', '%'.$search.'%');
                    }
                }
            });
        }

        $paginator = $query
            ->orderByDesc('last_synced_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn ($row): array => $this->formatDirectoryObject($row))
            ->values();

        return response()->json([
            'ok' => true,
            'meta' => $this->paginationMeta($paginator, [
                'connection_id' => $connectionId,
                'search' => $search ?: null,
            ]),
            'data' => $items,
        ]);
    }

    public function directoryObject(int $id): JsonResponse
    {
        $row = DB::table('ldap_directory_entries')
            ->where('id', $id)
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'message' => 'Directory object not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->formatDirectoryObject($row, includeAttributes: true),
        ]);
    }

    public function schema(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $page = $this->page($request);
        $connectionId = (int) $request->query('connection_id', 2);
        $search = trim((string) $request->query('search', ''));
        $type = trim((string) $request->query('type', ''));

        $query = DB::table('ldap_schema_entries')
            ->where('ldap_connection_id', $connectionId);

        if ($type !== '' && Schema::hasColumn('ldap_schema_entries', 'schema_type')) {
            $query->where('schema_type', $type);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                foreach (['schema_type', 'name', 'oid', 'kind', 'raw_definition'] as $column) {
                    if (Schema::hasColumn('ldap_schema_entries', $column)) {
                        $q->orWhere($column, 'like', '%'.$search.'%');
                    }
                }
            });
        }

        $paginator = $query
            ->orderBy('schema_type')
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn ($row): array => $this->formatSchema($row))
            ->values();

        return response()->json([
            'ok' => true,
            'meta' => $this->paginationMeta($paginator, [
                'connection_id' => $connectionId,
                'type' => $type ?: null,
                'search' => $search ?: null,
            ]),
            'data' => $items,
        ]);
    }

    public function schemaEntry(int $id): JsonResponse
    {
        $row = DB::table('ldap_schema_entries')
            ->where('id', $id)
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'message' => 'Schema entry not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->formatSchema($row, includeRaw: true),
        ]);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', $request->query('limit', 25));

        return max(1, min($perPage, 100));
    }

    private function page(Request $request): int
    {
        $page = (int) $request->query('page', 1);

        return max(1, $page);
    }

    private function paginationMeta($paginator, array $extra = []): array
    {
        return array_merge([
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'count' => $paginator->count(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ], $extra);
    }

    private function formatUser(object $row, bool $includeAttributes = false): array
    {
        $data = (array) $row;
        $attributes = $this->decodeJson($data['attributes'] ?? null);

        $payload = [
            'id' => $data['id'] ?? null,
            'ldap_connection_id' => $data['ldap_connection_id'] ?? null,
            'uid' => $data['uid'] ?? null,
            'dn' => $data['dn'] ?? null,
            'name' => $data['name'] ?? ($data['cn'] ?? null),
            'mail' => $data['mail'] ?? null,
            'status' => $data['status'] ?? null,
            'last_synced_at' => $data['last_synced_at'] ?? null,
        ];

        if ($includeAttributes) {
            $payload['attributes'] = $this->redactAttributes($attributes);
        }

        return $payload;
    }

    private function formatDirectoryObject(object $row, bool $includeAttributes = false): array
    {
        $data = (array) $row;

        $payload = [
            'id' => $data['id'] ?? null,
            'ldap_connection_id' => $data['ldap_connection_id'] ?? null,
            'dn' => $data['dn'] ?? null,
            'rdn' => $data['rdn'] ?? null,
            'object_classes' => $this->decodeJson($data['object_classes'] ?? null),
            'status' => $data['status'] ?? null,
            'last_seen_at' => $data['last_seen_at'] ?? null,
            'last_synced_at' => $data['last_synced_at'] ?? null,
        ];

        if ($includeAttributes) {
            $payload['attributes'] = $this->redactAttributes($this->decodeJson($data['attributes'] ?? null));
        }

        return $payload;
    }

    private function formatSchema(object $row, bool $includeRaw = false): array
    {
        $data = (array) $row;

        $payload = [
            'id' => $data['id'] ?? null,
            'ldap_connection_id' => $data['ldap_connection_id'] ?? null,
            'schema_type' => $data['schema_type'] ?? null,
            'name' => $data['name'] ?? null,
            'oid' => $data['oid'] ?? null,
            'kind' => $data['kind'] ?? null,
            'status' => $data['status'] ?? null,
            'last_seen_at' => $data['last_seen_at'] ?? null,
        ];

        if ($includeRaw) {
            $payload['raw_definition'] = $data['raw_definition'] ?? null;
        }

        return $payload;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function redactAttributes(array $attributes): array
    {
        $sensitive = [
            'userPassword',
            'sambaNTPassword',
            'unicodePwd',
            'password',
            'plainPassword',
            'secret',
            'token',
            'apiKey',
        ];

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = ['[REDACTED / EXISTS]'];
            }
        }

        return $attributes;
    }
}
