<?php

return [
    'base_url' => env('KEYCLOAK_BASE_URL', 'https://auth-ldap.ppsi.petra.ac.id'),
    'realm' => env('KEYCLOAK_REALM', 'petra'),
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'ldap-dashboard-petra-iodc'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
    'redirect_uri' => env('KEYCLOAK_REDIRECT_URI', env('APP_URL') . '/auth/callback'),
    'post_logout_redirect_uri' => env('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', env('APP_URL')),
    'scope' => env('KEYCLOAK_SCOPE', 'openid profile email'),
    'tls_verify' => filter_var(env('KEYCLOAK_TLS_VERIFY', true), FILTER_VALIDATE_BOOLEAN),

    'allowed_groups' => array_values(array_filter(array_map(
        fn (string $group): string => trim($group),
        explode(',', env('PETRA_ALLOWED_GROUPS', '/app-web/admin-role-web,app-web/admin-role-web'))
    ))),

    'forbidden_redirect' => env('PETRA_FORBIDDEN_REDIRECT', '/forbidden'),
];
