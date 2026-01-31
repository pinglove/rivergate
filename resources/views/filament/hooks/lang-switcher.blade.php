@php
    $currentLocale = app()->getLocale();
    $locales = ['en', 'fr', 'de'];
@endphp

<x-filament::dropdown placement="left-start" teleport="true">
    <x-slot name="trigger">
        <div class="px-3 py-2 text-sm flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-language" class="w-5 h-5" />
            <span>Language</span>
        </div>
    </x-slot>

    <x-filament::dropdown.header>
        Language
    </x-filament::dropdown.header>

    <x-filament::dropdown.list>
        {{-- Текущий --}}
        <x-filament::dropdown.list.item
            disabled
            color="gray"
        >
            Language: {{ strtoupper($currentLocale) }}
        </x-filament::dropdown.list.item>

        {{-- Остальные --}}
        @foreach ($locales as $locale)
            @continue($locale === $currentLocale)

            <x-filament::dropdown.list.item
                tag="a"
                :href="route('locale.switch', $locale)"
            >
                Language: {{ strtoupper($locale) }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
