<?php

return [
    'keycloak' => [
        'admin_console_url' => env(
            'PETRA_IAM_KEYCLOAK_ADMIN_CONSOLE_URL',
            'https://auth-ldap.ppsi.petra.ac.id/admin/master/console/'
        ),
    ],
];
