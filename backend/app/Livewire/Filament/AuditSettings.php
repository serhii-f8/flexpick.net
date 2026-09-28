<?php

namespace App\Livewire\Filament;

use App\Services\AuditReport\AuditSizeBands;
use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            'size_bands' => app(AuditSizeBands::class)->bands(),
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
                Section::make(__('Repository size bands'))
                    ->description(__('How many runs an audit costs, by lines of code. A repository above the last band is closed as too large for self-serve and the customer is asked to contact us. The pricing page and the Run-an-audit page show these same bands.'))
                    ->schema([
                        Repeater::make('size_bands')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('max_loc')->label(__('Up to (lines of code)'))->integer()->minValue(1)->required(),
                                TextInput::make('runs')->label(__('Runs'))->integer()->minValue(1)->maxValue(50)->required(),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->reorderable(false)
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                    $bands = AuditSizeBands::normalize(array_values((array) $value));

                                    if ($bands === null) {
                                        $fail(__('Each band needs a distinct size limit, and a larger band can never cost fewer runs.'));
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

        $this->configService->set(
            'audit.size_bands',
            (string) json_encode(AuditSizeBands::normalize(array_values($data['size_bands'] ?? [])) ?? AuditSizeBands::DEFAULT_BANDS),
        );

        Notification::make()->title(__('Audit settings saved'))->success()->send();
    }
}
