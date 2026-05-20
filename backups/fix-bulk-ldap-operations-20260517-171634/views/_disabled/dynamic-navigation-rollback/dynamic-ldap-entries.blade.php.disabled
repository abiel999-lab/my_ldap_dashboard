<x-filament-panels::page>
    @php
        $type = $this->currentType();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Dynamic LDAP Container
            </x-slot>

            <x-slot name="description">
                Halaman ini dibuat otomatis dari OU yang ditemukan di root LDAP dan disimpan di Entry Type Registry.
            </x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <div class="text-sm font-semibold text-gray-500">Label</div>
                    <div>{{ $type['label'] ?? 'N/A' }}</div>
                </div>

                <div>
                    <div class="text-sm font-semibold text-gray-500">Key</div>
                    <div>{{ $type['key'] ?? 'N/A' }}</div>
                </div>

                <div class="md:col-span-2">
                    <div class="text-sm font-semibold text-gray-500">Base DN</div>
                    <div class="break-all font-mono text-sm">{{ $type['base_dn'] ?? 'N/A' }}</div>
                </div>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
