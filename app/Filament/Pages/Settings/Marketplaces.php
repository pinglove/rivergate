<?php

namespace App\Filament\Pages\Settings;

use App\Models\UserMarketplace;
use App\Models\Marketplace;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class Marketplaces extends Page
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings.marketplaces';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Marketplaces';
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?int $navigationSort = 10;

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'marketplaces' => UserMarketplace::query()
                ->where('user_id', Auth::id())
                ->where('is_enabled', true)
                ->pluck('marketplace_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\CheckboxList::make('marketplaces')
                    ->label('Marketplaces')
                    ->options(
                        Marketplace::query()
                            ->where('is_active', true)
                            ->orderBy('id')
                            ->pluck('code', 'id')
                            ->mapWithKeys(fn ($code, $id) => [(int) $id => $code])
                            ->toArray()
                    )
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $userId   = Auth::id();
        $selected = array_map('intval', $this->data['marketplaces'] ?? []);

        $allMarketplaceIds = Marketplace::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($allMarketplaceIds as $marketplaceId) {
            UserMarketplace::updateOrCreate(
                [
                    'user_id'        => $userId,
                    'marketplace_id' => $marketplaceId,
                ],
                [
                    'is_enabled' => in_array($marketplaceId, $selected, true),
                ]
            );
        }

        Notification::make()
            ->title('Marketplaces saved')
            ->success()
            ->send();
    }
}
