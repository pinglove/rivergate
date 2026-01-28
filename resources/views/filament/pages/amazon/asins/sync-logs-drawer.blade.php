@php
$stepMap = [
    10 => ['label' => 'Started',          'color' => 'gray'],
    20 => ['label' => 'Report requested', 'color' => 'info'],
    30 => ['label' => 'Report downloaded','color' => 'info'],
    40 => ['label' => 'Catalog imported', 'color' => 'success'],
    90 => ['label' => 'Retry',            'color' => 'warning'],
    99 => ['label' => 'Error',            'color' => 'danger'],
];
@endphp

<div class="space-y-4">

    <div class="border-b pb-3">
        {{--
        <div class="text-lg font-semibold">ASIN Catalog Sync</div>
        --}}
        

        @if($sync)
            <div class="text-sm text-gray-600 mt-1">
                Status: <span class="font-medium capitalize">{{ $sync->status }}</span>
                · Attempts: {{ $sync->attempts }}
                · Started: {{ $sync->started_at?->format('Y-m-d H:i:s') }}
                @if($sync->finished_at)
                    · Finished: {{ $sync->finished_at->format('Y-m-d H:i:s') }}
                @endif
            </div>
        @endif
    </div>

    <div class="space-y-2">
        @forelse($logs as $log)
            @php
                $meta = $stepMap[$log->pipeline_step] ?? ['label' => 'Unknown step', 'color' => 'gray'];
                $payload = json_decode($log->payload, true) ?? [];
                $message = $payload['msg'] ?? '—';
            @endphp

            <div class="flex items-start gap-3 text-sm">
                <div class="w-20 text-gray-500 shrink-0">
                    {{ \Carbon\Carbon::parse($log->created_at)->format('H:i:s') }}
                </div>

                <div class="shrink-0">
                    <x-filament::badge color="{{ $meta['color'] }}">
                        {{ $meta['label'] }}
                    </x-filament::badge>
                </div>

                <div class="text-gray-800">
                    {{ $message }}
                </div>
            </div>
        @empty
            <div class="text-sm text-gray-500">No logs available</div>
        @endforelse
    </div>

</div>
