<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="space-y-2">
                <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                    Keycloak Admin Console
                </h2>

                <p class="text-sm text-gray-600 dark:text-gray-300">
                    This page provides a quick access link to the Keycloak administration console used by the LDAP Dashboard identity platform.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Public URL</div>
                    <div class="mt-2 break-all text-sm font-medium text-gray-950 dark:text-white">
                        {{ $this->getPublicUrl() }}
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Admin Console</div>
                    <div class="mt-2 break-all text-sm font-medium text-gray-950 dark:text-white">
                        {{ $this->getAdminConsoleUrl() }}
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Realm</div>
                    <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $this->getRealm() }}
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <a
                    href="{{ $this->getAdminConsoleUrl() }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500"
                >
                    Open Keycloak Admin Console
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <strong>Note:</strong>
            Keycloak is an external identity system. Changes in realm, client, role, group, user federation, authentication flow, or required action settings can affect login access to this dashboard.
        </div>
    </div>
</x-filament-panels::page>
