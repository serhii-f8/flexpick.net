<?php

namespace App\Livewire\Filament;

use App\Services\ConfigService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

class CardknoxSettings extends Component implements HasForms
{
    use InteractsWithForms;

    private ConfigService $configService;

    public ?array $data = [];

    public function boot(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    public function render()
    {
        return view('livewire.filament.cardknox-settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'transaction_key' => $this->configService->get('services.cardknox.transaction_key'),
            'ifields_key' => $this->configService->get('services.cardknox.ifields_key'),
            'webhook_pin' => $this->configService->get('services.cardknox.webhook_pin'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('transaction_key')
                            ->label(__('Transaction (API) Key'))
                            ->password()
                            ->revealable()
                            ->required(),
                        TextInput::make('ifields_key')
                            ->label(__('iFields Key'))
                            ->helperText(__('Public key used by the card form in the browser.'))
                            ->required(),
                        TextInput::make('webhook_pin')
                            ->label(__('Webhook PIN'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->regex('/^[A-Za-z0-9]{15,}$/')
                            ->helperText(__('Letters and digits only, at least 15 characters. Enter the same PIN in the Cardknox portal under Settings → Gateway Settings → Webhook.')),
                    ])->columnSpan([
                        'sm' => 6,
                        'xl' => 8,
                        '2xl' => 8,
                    ]),
                Section::make()->schema([
                    ViewField::make('how-to')
                        ->label(__('Cardknox Settings'))
                        ->view('filament.admin.resources.payment-provider-resource.pages.partials.cardknox-how-to'),
                ])->columnSpan([
                    'sm' => 6,
                    'xl' => 4,
                    '2xl' => 4,
                ]),
            ])->columns(12)
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->configService->set('services.cardknox.transaction_key', $data['transaction_key']);
        $this->configService->set('services.cardknox.ifields_key', $data['ifields_key']);
        $this->configService->set('services.cardknox.webhook_pin', $data['webhook_pin']);

        Notification::make()
            ->title(__('Settings Saved'))
            ->success()
            ->send();
    }
}
