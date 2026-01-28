<x-filament::page>

    {{-- Sync timeline --}}
    {{-- 
    @if (!empty($this->syncLogs))
        <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-semibold mb-3 text-gray-700 dark:text-gray-200">
                Last ASIN sync activity
            </h3>

            <ol class="space-y-2 text-sm">
                @foreach ($this->syncLogs as $log)
                    <li class="flex gap-3">
                        <div class="w-20 text-xs text-gray-500">
                            {{ \Carbon\Carbon::parse($log['created_at'])->format('H:i:s') }}
                        </div>

                        <div class="flex-1">
                            <span class="font-mono text-xs text-gray-400">
                                step {{ $log['pipeline_step'] }}
                            </span>

                            <div class="text-gray-800 dark:text-gray-100">
                                {{ data_get(json_decode($log['payload'], true), 'msg', '—') }}
                            </div>

                            @if (data_get(json_decode($log['payload'], true), 'error'))
                                <div class="mt-1 text-xs text-red-600">
                                    {{ data_get(json_decode($log['payload'], true), 'error') }}
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
    --}}
    
    {{ $this->table }}

</x-filament::page>
