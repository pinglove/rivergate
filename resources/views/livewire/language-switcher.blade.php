<div class="rg-language-switcher">
    <select
        wire:model="locale"
        class="fi-input fi-select-input text-sm py-1"
    >
        <option value="en">EN</option>
        <option value="fr">FR</option>
        <option value="de">DE</option>
    </select>
</div>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('refresh-page', () => {
            window.location.reload();
        });
    });
</script>

<style>
    /* ❗ НЕ ТРОГАЕМ .fi-topbar-end */

    /* Этот контейнер уже внутри блока профиля */
    .rg-language-switcher {
        margin-right: 0.75rem; /* отступ до аватарки */
        display: flex;
        align-items: center;
    }
</style>
