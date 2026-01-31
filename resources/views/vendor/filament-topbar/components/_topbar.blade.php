@props([
    'navigation',
])

@php
    use App\Models\Marketplace;
    use App\Models\UserMarketplace;

    $activeMarketplaceId = (int) session('active_marketplace');

    // все активные маркетплейсы пользователя
    $userMarketplaces = UserMarketplace::query()
        ->where('user_id', filament()->auth()->id())
        ->where('is_enabled', true)
        ->pluck('marketplace_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    // маркетплейсы (id => model)
    $marketplaces = Marketplace::query()
        ->whereIn('id', $userMarketplaces)
        ->where('is_active', true)
        ->get()
        ->keyBy('id');

    $activeMarketplaceCode = $marketplaces[$activeMarketplaceId]->code ?? '—';
@endphp


    <div class="fi-topbar sticky top-0 z-20 overflow-x-clip">
        <nav
            class="flex h-16 items-center gap-x-4 bg-white px-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 md:px-6 lg:px-8"
        >
            {{-- 🔧 GLOBAL CONTROLS --}}
            <div x-data="{ open: null }" class="flex items-center gap-x-3">

                {{-- ⚙️ SETTINGS --}}
                <div class="relative">
                    <button
                        type="button"
                        @click="open = open === 'settings' ? null : 'settings'"
                        class="fi-topbar-item flex items-center gap-1"
                    >
                        Settings
                        <x-heroicon-o-chevron-down class="h-4 w-4"/>
                    </button>

                    <div
                        x-show="open === 'settings'"
                        @click.outside="open = null"
                        x-transition
                        class="absolute right-0 z-50 mt-2 w-48 rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                    >
                        <a
                            href="{{ \App\Filament\Pages\Settings\Marketplaces::getUrl() }}"
                            class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                        >
                            Marketplaces
                        </a>
                    </div>
                </div>

                {{-- 🌍 MARKETPLACE --}}
                <div class="relative">
                    <button
                        type="button"
                        @click="open = open === 'marketplace' ? null : 'marketplace'"
                        class="fi-topbar-item flex items-center gap-1"
                    >
                        <x-heroicon-o-globe-alt class="h-4 w-4 opacity-70"/>
                        {{ $activeMarketplaceCode }}
                        <x-heroicon-o-chevron-down class="h-4 w-4"/>
                    </button>

                    <div
                        x-show="open === 'marketplace'"
                        @click.outside="open = null"
                        x-transition
                        class="absolute right-0 z-50 mt-2 w-40 rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                    >
                        @foreach ($marketplaces as $marketplaceId => $marketplace)
                            @if ($marketplaceId !== $activeMarketplaceId)
                                <a
                                    href="{{ route('marketplace.switch', $marketplaceId) }}"
                                    class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                                >
                                    {{ $marketplace->code }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- 🌐 LANGUAGE --}}
                <div class="relative">
                    <button
                        type="button"
                        @click="open = open === 'language' ? null : 'language'"
                        class="fi-topbar-item flex items-center gap-1"
                    >
                        {{ strtoupper(session('locale', 'en')) }}
                        <x-heroicon-o-chevron-down class="h-4 w-4"/>
                    </button>

                    <div
                        x-show="open === 'language'"
                        @click.outside="open = null"
                        x-transition
                        class="absolute right-0 z-50 mt-2 w-32 rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                    >
                        @foreach (['en', 'fr', 'de'] as $lang)
                            @if ($lang !== session('locale', 'en'))
                                <a
                                    href="{{ route('locale.switch', $lang) }}"
                                    class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                                >
                                    {{ strtoupper($lang) }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>

            </div>

            <div class="ms-auto flex items-center gap-x-4">
                @if (filament()->auth()->check())
                    <x-filament-panels::user-menu/>
                @endif
            </div>
        </nav>
    </div>
