<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->logRows as $log)
            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold">
                                {{ $log['name'] }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $log['component'] }}
                            </div>
                        </div>

                        <x-filament::badge :color="$log['exists'] ? 'success' : 'danger'">
                            {{ $log['status'] }}
                        </x-filament::badge>
                    </div>

                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $log['description'] }}
                    </div>

                    <div class="text-xs text-gray-500 dark:text-gray-400 break-all">
                        {{ $log['path'] }}
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">Size</div>
                            <div class="font-medium">{{ $log['size_mb'] }} MB</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">Modified</div>
                            <div class="font-medium">{{ $log['modified_at'] ?? 'N/A' }}</div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
