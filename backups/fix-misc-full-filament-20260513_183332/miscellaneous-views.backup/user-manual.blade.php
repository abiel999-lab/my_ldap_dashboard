<x-filament-panels::page>
    <div class="space-y-6">

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                Petra LDAP Dashboard User Manual
            </h2>

            <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                This manual explains how to use the LDAP Dashboard as an internal administration platform for directory management, LDAP operations, identity federation, observability, and maintenance.
            </p>

            <div class="mt-5 grid gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Dashboard URL</div>
                    <div class="mt-2 break-all text-sm font-medium text-gray-950 dark:text-white">{{ $this->getLdapDashboardUrl() }}</div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Base DN</div>
                    <div class="mt-2 break-all text-sm font-medium text-gray-950 dark:text-white">{{ $this->getBaseDn() }}</div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Kubernetes Namespace</div>
                    <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $this->getKubernetesNamespace() }}</div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Keycloak</div>
                    <div class="mt-2 break-all text-sm font-medium text-gray-950 dark:text-white">{{ $this->getKeycloakUrl() }}</div>
                </div>
            </div>
        </div>

        @php
            $sections = [
                [
                    'title' => '1. Dashboard',
                    'body' => 'The dashboard is the main overview page. It is used to monitor the platform status, quick actions, LDAP summary, operation status, security audit overview, and health indicators. Administrators should check this page first before performing critical LDAP actions.'
                ],
                [
                    'title' => '2. Directory Management',
                    'body' => 'Directory Management contains LDAP Connections, Users, Directory Object Manager, and Schema Browser. This section is used to manage LDAP server connections, inspect directory entries, view user and group objects, and understand available LDAP object classes and attributes.'
                ],
                [
                    'title' => '3. LDAP Connections',
                    'body' => 'LDAP Connections are used to define connection profiles to LDAP servers. Administrators can store host, port, encryption mode, bind DN, base DN, and connection status. Always test the connection before using it for import, export, or write operations.'
                ],
                [
                    'title' => '4. Users',
                    'body' => 'The Users module is used to view and manage LDAP user entries. User data is based on LDAP attributes. Important fields include UID, CN, SN, mail, DN, objectClass, account status, group membership, and operational attributes when available.'
                ],
                [
                    'title' => '5. Directory Object Manager',
                    'body' => 'Directory Object Manager is used to browse LDAP entries in a more generic way. This is useful when the object is not only a standard user or group, but also an OU, application group, role entry, or another LDAP object type.'
                ],
                [
                    'title' => '6. Schema Browser',
                    'body' => 'Schema Browser helps administrators inspect LDAP schema information such as object classes, attribute types, required attributes, optional attributes, and object structure. This is useful before creating or modifying LDAP entries.'
                ],
                [
                    'title' => '7. Operations',
                    'body' => 'Operations contains job-based tools for LDAP administration. This section includes operation jobs, job items, LDIF exports, operation logs, import template maker, LDAP transfer center, CRUD imports, apply plans, command executions, and queue jobs.'
                ],
                [
                    'title' => '8. Operation Jobs',
                    'body' => 'Operation Jobs record high-level LDAP actions. Each job may contain multiple job items. Use this page to track status, progress, success, warning, failed items, and execution metadata.'
                ],
                [
                    'title' => '9. Operation Job Items',
                    'body' => 'Operation Job Items represent each detailed action inside a job. For example, one import job may contain many item rows. Each item should show target DN, action type, status, message, and error information if available.'
                ],
                [
                    'title' => '10. LDIF Exports',
                    'body' => 'LDIF Exports are used to export LDAP entries into LDIF format. This is useful for backup, migration, documentation, and auditing. Administrators should verify base DN and filter before running an export.'
                ],
                [
                    'title' => '11. Import Template Maker',
                    'body' => 'Import Template Maker is used to generate structured templates for import operations. Templates help avoid incorrect CSV format, missing required fields, wrong identifier attributes, or invalid operation types.'
                ],
                [
                    'title' => '12. Imports CRUD Operations',
                    'body' => 'Imports CRUD Operations are used to upload CSV-based import files, preview the result, detect conflicts, and prepare safe LDAP write operations. Safe Mode and Preview Only should be enabled before applying changes.'
                ],
                [
                    'title' => '13. Import Apply Plans',
                    'body' => 'Import Apply Plans are used to review what will be changed before the operation is executed. Administrators should confirm target DN, action type, conflict status, and expected LDAP modification before applying.'
                ],
                [
                    'title' => '14. Command Executions',
                    'body' => 'Command Executions are used to run controlled operational commands. This module should be used carefully. Prefer preview, safe mode, or read-only commands before executing destructive actions.'
                ],
                [
                    'title' => '15. Queue Jobs',
                    'body' => 'Queue Jobs are used to inspect background jobs. If an operation is stuck, failed, or delayed, administrators should check this section together with Failed Jobs and System Logs.'
                ],
                [
                    'title' => '16. Observability',
                    'body' => 'Observability contains Audit Logs, Failed Jobs, System Logs, and Health Checks. This section is used to monitor platform behavior, detect errors, inspect failed background processes, and verify system health.'
                ],
                [
                    'title' => '17. Audit Logs',
                    'body' => 'Audit Logs record administrative activities. This is important for accountability, troubleshooting, and security review. Critical actions such as user changes, imports, exports, and command executions should be logged.'
                ],
                [
                    'title' => '18. Failed Jobs',
                    'body' => 'Failed Jobs show background processes that failed during execution. Administrators should inspect the error message, payload, queue name, and failure time before retrying or manually fixing the issue.'
                ],
                [
                    'title' => '19. System Logs',
                    'body' => 'System Logs help administrators inspect application-level messages and errors. This page is useful when debugging dashboard errors, failed LDAP actions, authentication problems, and unexpected behavior.'
                ],
                [
                    'title' => '20. Health Checks',
                    'body' => 'Health Checks show the status of important platform components. This may include application health, database access, LDAP connection, queue worker condition, storage availability, and external service reachability.'
                ],
                [
                    'title' => '21. Keycloak',
                    'body' => 'Keycloak is used as the identity provider. It manages login, realm configuration, clients, roles, groups, authentication flows, required actions, and identity federation settings. Use the Keycloak menu under Miscellaneous to open the admin console.'
                ],
                [
                    'title' => '22. Kubernetes Deployment',
                    'body' => 'The platform is deployed in Kubernetes. Administrators should verify pods, services, ingress, TLS, logs, secrets, config maps, and namespace resources when troubleshooting production issues.'
                ],
                [
                    'title' => '23. Safety Rules',
                    'body' => 'Before running LDAP write operations, always use preview mode, check the target DN, verify the LDAP filter, review generated plans, confirm backup availability, and avoid destructive actions unless the impact is fully understood.'
                ],
                [
                    'title' => '24. Troubleshooting Flow',
                    'body' => 'When an issue happens, check the page error first, then audit logs, system logs, failed jobs, queue jobs, Laravel logs, Kubernetes pod logs, LDAP connection status, and Keycloak session or client configuration.'
                ],
            ];
        @endphp

        <div class="grid gap-4">
            @foreach ($sections as $section)
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-base font-bold text-gray-950 dark:text-white">
                        {{ $section['title'] }}
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ $section['body'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-base font-bold text-gray-950 dark:text-white">
                25. Useful Kubernetes Commands
            </h3>

            <div class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100">
<pre>microk8s kubectl get pods -A
microk8s kubectl -n {{ $this->getKubernetesNamespace() }} get all
microk8s kubectl -n {{ $this->getKubernetesNamespace() }} logs deploy/&lt;deployment-name&gt; --tail=200
microk8s kubectl -n {{ $this->getKubernetesNamespace() }} describe pod &lt;pod-name&gt;
microk8s kubectl -n {{ $this->getKubernetesNamespace() }} rollout restart deployment &lt;deployment-name&gt;
microk8s kubectl get ingress -A
microk8s kubectl get certificate,certificaterequest,order,challenge -A</pre>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-base font-bold text-gray-950 dark:text-white">
                26. Useful Laravel Commands
            </h3>

            <div class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100">
<pre>php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan filament:clear-cached-components
php artisan queue:failed
php artisan queue:work
php artisan serve</pre>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-base font-bold text-gray-950 dark:text-white">
                27. External Links
            </h3>

            <div class="mt-4 space-y-3 text-sm">
                <div>
                    <span class="font-semibold text-gray-950 dark:text-white">LDAP Dashboard:</span>
                    <a class="text-primary-600 hover:underline" href="{{ $this->getLdapDashboardUrl() }}" target="_blank" rel="noopener noreferrer">
                        {{ $this->getLdapDashboardUrl() }}
                    </a>
                </div>

                <div>
                    <span class="font-semibold text-gray-950 dark:text-white">Keycloak Public URL:</span>
                    <a class="text-primary-600 hover:underline" href="{{ $this->getKeycloakUrl() }}" target="_blank" rel="noopener noreferrer">
                        {{ $this->getKeycloakUrl() }}
                    </a>
                </div>

                <div>
                    <span class="font-semibold text-gray-950 dark:text-white">Keycloak Admin Console:</span>
                    <a class="text-primary-600 hover:underline" href="{{ $this->getKeycloakAdminConsoleUrl() }}" target="_blank" rel="noopener noreferrer">
                        {{ $this->getKeycloakAdminConsoleUrl() }}
                    </a>
                </div>
            </div>
        </div>

    </div>
</x-filament-panels::page>
