@php
    $overview = $inspection['overview'] ?? [];
    $stats = $inspection['stats'] ?? [];
    $objectClasses = $inspection['object_classes'] ?? [];
    $normalAttributes = $inspection['normal_attributes'] ?? [];
    $operationalAttributes = $inspection['operational_attributes'] ?? [];
    $rawJson = $inspection['raw_attributes_json'] ?? '{}';

    $overviewRows = [
        ['DN', $overview['dn'] ?? 'N/A'],
        ['RDN', $overview['rdn'] ?? 'N/A'],
        ['Parent DN', $overview['parent_dn'] ?? 'N/A'],
        ['Connection', $overview['connection'] ?? 'N/A'],
        ['Status', $overview['status'] ?? 'N/A'],
        ['UID', $overview['uid'] ?? 'N/A'],
        ['CN', $overview['cn'] ?? 'N/A'],
        ['SN', $overview['sn'] ?? 'N/A'],
        ['Given Name', $overview['givenName'] ?? 'N/A'],
        ['Display Name', $overview['displayName'] ?? 'N/A'],
        ['Mail', $overview['mail'] ?? 'N/A'],
        ['OU', $overview['ou'] ?? 'N/A'],
        ['Description', $overview['description'] ?? 'N/A'],
    ];
@endphp

<div class="space-y-6">
    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">1. Entry Overview</h2>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($overviewRows as [$label, $value])
                <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</div>
                    <div class="mt-1 break-all text-sm text-white">{{ $value ?: 'N/A' }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">2. Summary</h2>

        <div class="grid gap-3 md:grid-cols-5">
            <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 text-center">
                <div class="text-xs text-gray-400">Object Classes</div>
                <div class="text-2xl font-bold text-white">{{ $stats['object_class_count'] ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 text-center">
                <div class="text-xs text-gray-400">Normal Attributes</div>
                <div class="text-2xl font-bold text-white">{{ $stats['normal_attribute_count'] ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 text-center">
                <div class="text-xs text-gray-400">Operational Attributes</div>
                <div class="text-2xl font-bold text-white">{{ $stats['operational_attribute_count'] ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 text-center">
                <div class="text-xs text-gray-400">Normal Values</div>
                <div class="text-2xl font-bold text-white">{{ $stats['normal_value_count'] ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-950/50 p-3 text-center">
                <div class="text-xs text-gray-400">Operational Values</div>
                <div class="text-2xl font-bold text-white">{{ $stats['operational_value_count'] ?? 0 }}</div>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">3. Object Classes</h2>

        <div class="overflow-hidden rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70 text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">No</th>
                        <th class="px-3 py-2 text-left">Object Class</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($objectClasses as $row)
                        <tr class="border-t border-gray-800">
                            <td class="px-3 py-2 text-gray-300">{{ $row['no'] }}</td>
                            <td class="px-3 py-2 font-medium text-white">{{ $row['name'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="px-3 py-3 text-gray-400">No objectClass found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">4. Directory Attributes</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70 text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">No</th>
                        <th class="px-3 py-2 text-left">Attribute</th>
                        <th class="px-3 py-2 text-left">Count</th>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Values</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($normalAttributes as $row)
                        <tr class="border-t border-gray-800 align-top">
                            <td class="px-3 py-3 text-gray-300">{{ $row['no'] }}</td>
                            <td class="px-3 py-3 font-semibold text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-3 text-gray-300">{{ $row['value_count'] }}</td>
                            <td class="px-3 py-3">
                                @if ($row['is_multi'])
                                    <span class="rounded bg-yellow-500/20 px-2 py-1 text-xs text-yellow-300">Multi Value</span>
                                @else
                                    <span class="rounded bg-green-500/20 px-2 py-1 text-xs text-green-300">Single Value</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="space-y-2">
                                    @forelse ($row['values'] as $value)
                                        <div class="rounded border border-gray-800 bg-gray-950/60 px-2 py-2 text-white break-all">{{ $value }}</div>
                                    @empty
                                        <span class="text-gray-400">No value</span>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-3 text-gray-400">No normal attributes found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">5. Operational / Read-Only Attributes</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70 text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">No</th>
                        <th class="px-3 py-2 text-left">Attribute</th>
                        <th class="px-3 py-2 text-left">Count</th>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Values</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($operationalAttributes as $row)
                        <tr class="border-t border-gray-800 align-top">
                            <td class="px-3 py-3 text-gray-300">{{ $row['no'] }}</td>
                            <td class="px-3 py-3 font-semibold text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-3 text-gray-300">{{ $row['value_count'] }}</td>
                            <td class="px-3 py-3">
                                <span class="rounded bg-blue-500/20 px-2 py-1 text-xs text-blue-300">Read Only</span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="space-y-2">
                                    @forelse ($row['values'] as $value)
                                        <div class="rounded border border-gray-800 bg-gray-950/60 px-2 py-2 text-white break-all">{{ $value }}</div>
                                    @empty
                                        <span class="text-gray-400">No value</span>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-3 text-gray-400">No operational attributes found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-4 text-lg font-bold text-white">6. Raw JSON / Debug</h2>
        <pre class="max-h-96 overflow-auto rounded-lg border border-gray-800 bg-gray-950/70 p-4 text-xs text-gray-200">{{ $rawJson }}</pre>
    </section>
</div>
