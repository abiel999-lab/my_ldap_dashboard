@php
    $inspection = $inspection ?? [];
    $overview = $inspection['overview'] ?? [];
    $stats = $inspection['stats'] ?? [];
    $objectClasses = $inspection['object_classes'] ?? [];
    $normalAttributes = $inspection['normal_attributes'] ?? [];
    $operationalAttributes = $inspection['operational_attributes'] ?? [];
    $rawJson = $inspection['raw_attributes_json'] ?? '{}';

    $overviewRows = [
        ['label' => 'DN', 'value' => $overview['dn'] ?? 'N/A'],
        ['label' => 'RDN', 'value' => $overview['rdn'] ?? 'N/A'],
        ['label' => 'Parent DN', 'value' => $overview['parent_dn'] ?? 'N/A'],
        ['label' => 'Connection', 'value' => $overview['connection'] ?? 'N/A'],
        ['label' => 'Status', 'value' => $overview['status'] ?? 'N/A'],
        ['label' => 'UID', 'value' => $overview['uid'] ?? 'N/A'],
        ['label' => 'CN', 'value' => $overview['cn'] ?? 'N/A'],
        ['label' => 'SN', 'value' => $overview['sn'] ?? 'N/A'],
        ['label' => 'Given Name', 'value' => $overview['givenName'] ?? 'N/A'],
        ['label' => 'Display Name', 'value' => $overview['displayName'] ?? 'N/A'],
        ['label' => 'Mail', 'value' => $overview['mail'] ?? 'N/A'],
        ['label' => 'OU', 'value' => $overview['ou'] ?? 'N/A'],
        ['label' => 'Description', 'value' => $overview['description'] ?? 'N/A'],
    ];

    $statRows = [
        ['label' => 'Object Classes', 'value' => $stats['object_class_count'] ?? 0],
        ['label' => 'Normal Attributes', 'value' => $stats['normal_attribute_count'] ?? 0],
        ['label' => 'Operational Attributes', 'value' => $stats['operational_attribute_count'] ?? 0],
        ['label' => 'Normal Values', 'value' => $stats['normal_value_count'] ?? 0],
        ['label' => 'Operational Values', 'value' => $stats['operational_value_count'] ?? 0],
    ];
@endphp

<div class="space-y-6">
    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-lg font-bold text-white">1. Entry Overview</h2>
            <span class="rounded-lg bg-primary-600/20 px-3 py-1 text-xs font-medium text-primary-300">
                Apache-style inspector view
            </span>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($overviewRows as $row)
                <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        {{ $row['label'] }}
                    </div>
                    <div class="mt-1 break-all text-sm text-white">
                        {{ $row['value'] ?: 'N/A' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-3 text-lg font-bold text-white">2. Entry Statistics</h2>

        <div class="grid gap-3 md:grid-cols-5">
            @foreach ($statRows as $row)
                <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 text-center">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        {{ $row['label'] }}
                    </div>
                    <div class="mt-2 text-2xl font-bold text-white">
                        {{ $row['value'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-3 text-lg font-bold text-white">3. Object Classes</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">No</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Object Class</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($objectClasses as $row)
                        <tr class="border-t border-gray-800">
                            <td class="px-3 py-2 text-gray-200">{{ $row['no'] }}</td>
                            <td class="px-3 py-2 text-white">{{ $row['name'] }}</td>
                        </tr>
                    @empty
                        <tr class="border-t border-gray-800">
                            <td colspan="2" class="px-3 py-3 text-gray-400">No objectClass found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-3 text-lg font-bold text-white">4. Directory Attributes</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">No</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Attribute</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Value Count</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Type</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Values</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($normalAttributes as $row)
                        <tr class="border-t border-gray-800 align-top">
                            <td class="px-3 py-3 text-gray-200">{{ $row['no'] }}</td>
                            <td class="px-3 py-3 font-medium text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-3 text-gray-200">{{ $row['value_count'] }}</td>
                            <td class="px-3 py-3">
                                @if($row['is_multi'])
                                    <span class="rounded-md bg-warning-500/20 px-2 py-1 text-xs font-medium text-warning-300">
                                        Multi Value
                                    </span>
                                @else
                                    <span class="rounded-md bg-success-500/20 px-2 py-1 text-xs font-medium text-success-300">
                                        Single Value
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="space-y-2">
                                    @forelse ($row['values'] as $value)
                                        <div class="rounded-md border border-gray-800 bg-gray-950/50 px-2 py-2 text-white break-all">
                                            {{ $value }}
                                        </div>
                                    @empty
                                        <div class="text-gray-400">No value</div>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="border-t border-gray-800">
                            <td colspan="5" class="px-3 py-3 text-gray-400">No normal attributes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-3 text-lg font-bold text-white">5. Operational / Read-Only Attributes</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-950/70">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">No</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Attribute</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Value Count</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Type</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-300">Values</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($operationalAttributes as $row)
                        <tr class="border-t border-gray-800 align-top">
                            <td class="px-3 py-3 text-gray-200">{{ $row['no'] }}</td>
                            <td class="px-3 py-3 font-medium text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-3 text-gray-200">{{ $row['value_count'] }}</td>
                            <td class="px-3 py-3">
                                @if($row['is_multi'])
                                    <span class="rounded-md bg-warning-500/20 px-2 py-1 text-xs font-medium text-warning-300">
                                        Multi Value
                                    </span>
                                @else
                                    <span class="rounded-md bg-info-500/20 px-2 py-1 text-xs font-medium text-info-300">
                                        Single Value
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="space-y-2">
                                    @forelse ($row['values'] as $value)
                                        <div class="rounded-md border border-gray-800 bg-gray-950/50 px-2 py-2 text-white break-all">
                                            {{ $value }}
                                        </div>
                                    @empty
                                        <div class="text-gray-400">No value</div>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="border-t border-gray-800">
                            <td colspan="5" class="px-3 py-3 text-gray-400">No operational attributes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
        <h2 class="mb-3 text-lg font-bold text-white">6. Raw JSON / Debug</h2>

        <div class="overflow-x-auto rounded-lg border border-gray-800 bg-gray-950/60 p-4">
            <pre class="whitespace-pre-wrap break-all text-xs text-gray-200">{{ $rawJson }}</pre>
        </div>
    </div>
</div>
