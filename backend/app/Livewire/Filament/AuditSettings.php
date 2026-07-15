<?php

namespace App\Livewire\Filament;

use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

class AuditSettings extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    private ConfigService $configService;

    public function render()
    {
        return view('livewire.filament.audit-settings');
    }

    public function boot(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    public function mount(): void
    {
        $this->form->fill([
            'prompt_template' => $this->configService->get('audit.prompt_template', ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Analysis Prompt'))
                    ->description(__('Template for the AI analysis prompt. Leave blank to use the built-in default shown below. Must contain the {metrics} and {excerpts} placeholders.'))
                    ->schema([
                        Textarea::make('prompt_template')
                            ->label(__('Prompt template'))
                            ->rows(12)
                            ->helperText(__('Built-in default:').' '.PromptComposer::DEFAULT_TEMPLATE)
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                    if (trim((string) $value) !== '' && ! app(PromptComposer::class)->templateIsValid((string) $value)) {
                                        $fail(__('The template must contain both {metrics} and {excerpts} placeholders.'));
                                    }
                                },
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->configService->set('audit.prompt_template', $data['prompt_template'] ?? '');

        Notification::make()->title(__('Audit settings saved'))->success()->send();
    }
}
