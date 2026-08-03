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
        $stored = (string) $this->configService->get('audit.prompt_template', '');

        $this->form->fill([
            'prompt_template' => $stored,
        ]);

        // A pre-Phase-11 override lacking {groups} would otherwise keep
        // validating and silently produce prompts with no findings at all —
        // the operator must be told the default template is active instead
        // (spec §7.3).
        if (trim($stored) !== '' && ! app(PromptComposer::class)->storedOverrideIsUsable()) {
            Notification::make()
                ->title(__('Saved prompt template is not being used'))
                ->body(__('Your saved prompt template is missing the {groups} placeholder and is not being used. The default template is active.'))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Analysis Prompt'))
                    ->description(__('Template for the AI analysis prompt. Leave blank to use the built-in default shown below. Must contain the {metrics}, {groups}, and {excerpts} placeholders.'))
                    ->schema([
                        Textarea::make('prompt_template')
                            ->label(__('Prompt template'))
                            ->rows(12)
                            ->helperText(__('Built-in default:').' '.PromptComposer::DEFAULT_TEMPLATE)
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                    if (trim((string) $value) !== '' && ! app(PromptComposer::class)->templateIsValid((string) $value)) {
                                        $fail(__('The template must contain the {metrics}, {groups}, and {excerpts} placeholders.'));
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
