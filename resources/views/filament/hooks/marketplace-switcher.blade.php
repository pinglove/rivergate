@php
    use App\Models\Marketplace;
    use App\Models\UserMarketplace;

    $user = auth()->user();
    if (! $user) {
        return;
    }

    $activeMarketplaceId = (int) session('active_marketplace');

    $userMarketplaceIds = UserMarketplace::query()
        ->where('user_id', $user->id)
        ->where('is_enabled', true)
        ->pluck('marketplace_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    $marketplaces = Marketplace::query()
        ->whereIn('id', $userMarketplaceIds)
        ->where('is_active', true)
        ->get();

    $activeMarketplace = $marketplaces->firstWhere('id', $activeMarketplaceId);
@endphp

<x-filament::dropdown placement="left-start" teleport="true">
    <x-slot name="trigger">
        <div class="px-3 py-2 text-sm flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-globe-alt" class="w-5 h-5" />
            <span>Marketplaces</span>
        </div>
    </x-slot>

    <x-filament::dropdown.header>
        Marketplaces
    </x-filament::dropdown.header>

    <x-filament::dropdown.list>
        {{-- Текущий --}}
        <x-filament::dropdown.list.item
            disabled
            color="gray"
        >
            Marketplace: {{ $activeMarketplace->code ?? '—' }}
        </x-filament::dropdown.list.item>

        {{-- Остальные --}}
        @foreach ($marketplaces as $marketplace)
            @continue($marketplace->id === $activeMarketplaceId)

            <x-filament::dropdown.list.item
                tag="a"
                :href="route('marketplace.switch', $marketplace->id)"
            >
                Switch to {{ $marketplace->code }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
