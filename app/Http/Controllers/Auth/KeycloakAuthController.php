<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class KeycloakAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $freshLogin = $request->boolean('fresh') || $request->boolean('force');

        if (Auth::guard('web')->check() && ! $freshLogin) {
            return redirect('/admin');
        }

        if ($freshLogin) {
            $this->clearLocalSession($request);
        }

        $state = Str::random(48);
        $nonce = Str::random(48);

        $request->session()->put('keycloak_state', $state);
        $request->session()->put('keycloak_nonce', $nonce);

        $query = [
            'client_id' => config('keycloak.client_id'),
            'redirect_uri' => config('keycloak.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('keycloak.scope'),
            'state' => $state,
            'nonce' => $nonce,
        ];

        if ($freshLogin) {
            $query['prompt'] = 'login';
            $query['max_age'] = 0;
        }

        return redirect()->away($this->issuerUrl() . '/protocol/openid-connect/auth?' . http_build_query($query));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            abort(403, 'Keycloak error: ' . $request->string('error_description', $request->string('error')));
        }

        $expectedState = $request->session()->pull('keycloak_state');

        if (! $expectedState || $expectedState !== $request->query('state')) {
            abort(419, 'Invalid Keycloak state.');
        }

        $code = $request->query('code');

        if (! $code) {
            abort(400, 'Missing Keycloak authorization code.');
        }

        $token = $this->requestToken($code);
        $userInfo = $this->requestUserInfo($token['access_token']);
        $tokenPayload = $this->decodeJwtPayload($token['access_token']);

        $email = $userInfo['email'] ?? null;

        if (! $email) {
            abort(403, 'Keycloak account does not provide email.');
        }

        $name = $userInfo['name']
            ?? $userInfo['preferred_username']
            ?? $email;

        $groups = $this->extractGroups($userInfo, $tokenPayload);
        $roles = $this->extractRolesFromPayload($tokenPayload);

        session([
            'keycloak_access_token' => $token['access_token'] ?? null,
            'keycloak_id_token' => $token['id_token'] ?? null,
            'keycloak_refresh_token' => $token['refresh_token'] ?? null,
            'keycloak_user' => [
                'sub' => $userInfo['sub'] ?? null,
                'email' => $email,
                'name' => $name,
                'preferred_username' => $userInfo['preferred_username'] ?? null,
                'picture' => $userInfo['picture'] ?? null,
                'groups' => $groups,
                'roles' => $roles,
            ],
        ]);

        if (! $this->hasAllowedGroup($groups, $roles)) {
            Log::warning('Keycloak login denied because required group is missing', [
                'email' => $email,
                'groups' => $groups,
                'required_groups' => config('keycloak.allowed_groups'),
                'ip' => $request->ip(),
            ]);

            Auth::guard('web')->logout();

            $request->session()->put('forbidden_reason', 'Akun Keycloak Anda tidak memiliki akses ke aplikasi Petra LDAP Dashboard.');
            $request->session()->put('forbidden_email', $email);
            $request->session()->put('forbidden_groups', $groups);

            return redirect(config('keycloak.forbidden_redirect', '/forbidden'));
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt(Str::random(64)),
                'email_verified_at' => now(),
                'avatar_url' => $userInfo['picture'] ?? null,
            ],
        );

        Auth::guard('web')->login($user, true);

        $request->session()->regenerate();

        session([
            'keycloak_access_token' => $token['access_token'] ?? null,
            'keycloak_id_token' => $token['id_token'] ?? null,
            'keycloak_refresh_token' => $token['refresh_token'] ?? null,
            'keycloak_user' => [
                'sub' => $userInfo['sub'] ?? null,
                'email' => $email,
                'name' => $name,
                'preferred_username' => $userInfo['preferred_username'] ?? null,
                'picture' => $userInfo['picture'] ?? null,
                'groups' => $groups,
                'roles' => $roles,
            ],
        ]);

        Log::info('Keycloak login success', [
            'user_id' => $user->id,
            'email' => $email,
            'name' => $name,
            'groups' => $groups,
            'ip' => $request->ip(),
        ]);

        return redirect('/admin');
    }

    public function logout(Request $request): RedirectResponse
    {
        $idToken = session('keycloak_id_token');

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            DB::table('sessions')
                ->where('id', $request->session()->getId())
                ->delete();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Cookie::queue(Cookie::forget('petra_ldap_dashboard_session'));
        Cookie::queue(Cookie::forget('XSRF-TOKEN'));
        Cookie::queue(Cookie::forget('laravel_session'));

        $query = [
            'client_id' => config('keycloak.client_id'),
            'post_logout_redirect_uri' => config('keycloak.post_logout_redirect_uri'),
        ];

        if (! empty($idToken)) {
            $query['id_token_hint'] = $idToken;
        }

        return redirect()->away($this->issuerUrl() . '/protocol/openid-connect/logout?' . http_build_query($query));
    }

    public function signedOut(Request $request): RedirectResponse
    {
        $this->clearLocalSession($request);

        return redirect('/auth/redirect?fresh=1');
    }

    public function forbidden(Request $request)
    {
        $email = e($request->session()->get('forbidden_email', 'Unknown account'));
        $reason = e($request->session()->get('forbidden_reason', 'Anda tidak punya akses ke aplikasi ini.'));
        $groups = $request->session()->get('forbidden_groups', []);

        $groupsHtml = collect($groups)
            ->map(fn ($group) => '<code>' . e((string) $group) . '</code>')
            ->implode(' ');

        if ($groupsHtml === '') {
            $groupsHtml = '<span class="muted">Tidak ada group terbaca dari token Keycloak.</span>';
        }

        return response(<<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Akses Ditolak - Petra LDAP Dashboard</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            --petra-blue: #005baa;
            --petra-dark: #020617;
            --petra-card: #0f172a;
            --petra-border: rgba(148, 163, 184, 0.16);
            --text: #e5e7eb;
            --muted: #a7b4c8;
            --danger-bg: rgba(239, 68, 68, 0.14);
            --danger-text: #fecaca;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(0, 91, 170, 0.20), transparent 34rem),
                radial-gradient(circle at bottom right, rgba(245, 164, 0, 0.08), transparent 30rem),
                var(--petra-dark);
            font-weight: 400;
        }

        .card {
            width: min(700px, calc(100vw - 32px));
            padding: 34px;
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid var(--petra-border);
            box-shadow: 0 35px 90px rgba(0, 0, 0, 0.30);
        }

        .brand {
            margin-bottom: 24px;
        }

        .brand img {
            width: 220px;
            height: auto;
            object-fit: contain;
            display: block;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 12px;
            background: var(--danger-bg);
            color: var(--danger-text);
            font-weight: 400;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 30px;
            line-height: 1.15;
            font-weight: 400;
            letter-spacing: -0.03em;
        }

        p {
            color: var(--muted);
            line-height: 1.65;
            font-weight: 400;
        }

        strong {
            font-weight: 400;
            color: #f8fafc;
        }

        .box {
            margin-top: 18px;
            padding: 16px;
            border-radius: 16px;
            background: rgba(2, 6, 23, 0.44);
            border: 1px solid rgba(148, 163, 184, 0.12);
        }

        code {
            display: inline-flex;
            margin: 4px 4px 0 0;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(0, 91, 170, 0.18);
            color: #bfdbfe;
            font-weight: 400;
        }

        .muted {
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 26px;
            flex-wrap: wrap;
        }

        a {
            text-decoration: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 12px;
            color: white;
            font-weight: 400;
            background: linear-gradient(135deg, var(--petra-blue), #003f7f);
        }

        .btn-secondary {
            background: rgba(148, 163, 184, 0.12);
            color: var(--text);
            border: 1px solid rgba(148, 163, 184, 0.18);
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <img src="/img/logo-light.png" alt="Petra">
        </div>

        <div class="badge">Akses Ditolak</div>

        <h1>Kamu tidak punya akses ke aplikasi ini.</h1>

        <p>{$reason}</p>

        <div class="box">
            <strong>Akun:</strong>
            <p>{$email}</p>

            <strong>Group terbaca:</strong>
            <p>{$groupsHtml}</p>

            <strong>Group yang dibutuhkan:</strong>
            <p><code>/app-web/admin-role-web</code> <code>app-web/admin-role-web</code></p>
        </div>

        <div class="actions">
            <a class="btn" href="/auth/logout">Logout & Login Ulang</a>
            <a class="btn btn-secondary" href="/auth/logout">Coba Login Lagi</a>
        </div>
    </main>
</body>
</html>
HTML, 403);
    }

    private function clearLocalSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            DB::table('sessions')
                ->where('id', $request->session()->getId())
                ->delete();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Cookie::queue(Cookie::forget('petra_ldap_dashboard_session'));
        Cookie::queue(Cookie::forget('XSRF-TOKEN'));
        Cookie::queue(Cookie::forget('laravel_session'));
    }

    private function requestToken(string $code): array
    {
        $response = Http::withOptions([
            'verify' => config('keycloak.tls_verify'),
        ])->asForm()->post($this->issuerUrl() . '/protocol/openid-connect/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('keycloak.client_id'),
            'client_secret' => config('keycloak.client_secret'),
            'redirect_uri' => config('keycloak.redirect_uri'),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            Log::error('Keycloak token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Failed to request Keycloak token.');
        }

        return $response->json();
    }

    private function requestUserInfo(string $accessToken): array
    {
        $response = Http::withOptions([
            'verify' => config('keycloak.tls_verify'),
        ])->withToken($accessToken)
            ->get($this->issuerUrl() . '/protocol/openid-connect/userinfo');

        if (! $response->successful()) {
            Log::error('Keycloak userinfo request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Failed to request Keycloak user info.');
        }

        return $response->json();
    }

    private function issuerUrl(): string
    {
        return rtrim(config('keycloak.base_url'), '/') . '/realms/' . config('keycloak.realm');
    }

    private function decodeJwtPayload(?string $jwt): array
    {
        if (! $jwt) {
            return [];
        }

        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractGroups(array $userInfo, array $tokenPayload): array
    {
        $groups = [];

        foreach ([
            $userInfo['groups'] ?? [],
            $tokenPayload['groups'] ?? [],
            $tokenPayload['group'] ?? [],
        ] as $candidateGroups) {
            if (is_string($candidateGroups)) {
                $groups[] = $candidateGroups;
            }

            if (is_array($candidateGroups)) {
                foreach ($candidateGroups as $group) {
                    if (is_string($group)) {
                        $groups[] = $group;
                    }
                }
            }
        }

        return array_values(array_unique($groups));
    }

    private function extractRolesFromPayload(array $tokenPayload): array
    {
        return [
            'realm' => $tokenPayload['realm_access']['roles'] ?? [],
            'resource' => $tokenPayload['resource_access'] ?? [],
        ];
    }

    private function hasAllowedGroup(array $groups, array $roles): bool
    {
        $normalizedGroups = array_map(
            fn (string $group): string => $this->normalizeGroup($group),
            $groups
        );

        foreach (config('keycloak.allowed_groups', []) as $allowedGroup) {
            if (in_array($this->normalizeGroup((string) $allowedGroup), $normalizedGroups, true)) {
                return true;
            }
        }

        foreach ($normalizedGroups as $group) {
            $segments = array_values(array_filter(explode('/', $group)));

            if (in_array('app-web', $segments, true) && in_array('admin-role-web', $segments, true)) {
                return true;
            }
        }

        $realmRoles = $roles['realm'] ?? [];

        if (in_array('admin-role-web', $realmRoles, true)) {
            foreach ($normalizedGroups as $group) {
                if (str_contains($group, 'app-web')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeGroup(string $group): string
    {
        return trim(trim($group), '/');
    }
}
