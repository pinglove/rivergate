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
        ->orderBy('code')
        ->get();
@endphp

<form
    method="POST"
    action="{{ route('marketplace.switch.post') }}"
    
>
    @csrf

    <label class="block text-xs text-gray-500 mb-1">
        Marketplace
    </label>

    <select
        name="marketplace_id"
        class="w-full text-sm rounded-md border-gray-300
               focus:border-primary-500 focus:ring-primary-500"
        onchange="this.form.submit()"
    >
        @foreach ($marketplaces as $marketplace)
            <option
                value="{{ $marketplace->id }}"
                @selected($marketplace->id === $activeMarketplaceId)
            >
                {{ $marketplace->code }}
            </option>
        @endforeach
    </select>
</form>
