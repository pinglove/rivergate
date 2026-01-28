<?php

namespace App\Livewire;

use Livewire\Component;

class LanguageSwitcher extends Component
{
    public string $locale = 'en';

    public function mount(): void
    {
        $this->locale = session('locale', 'en');
    }

    public function updatedLocale(string $value): void
    {
        session(['locale' => $value]);

        $this->dispatch('refresh-page');
    }

    public function render()
    {
        return view('livewire.language-switcher');
    }
}
