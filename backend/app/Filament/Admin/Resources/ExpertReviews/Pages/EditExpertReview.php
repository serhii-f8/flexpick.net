<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\ReportPayload;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditExpertReview extends EditRecord
{
    protected static string $resource = ExpertReviewResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Risks'))->schema([
                Repeater::make('risks')
                    ->label('')
                    ->schema([
                        Select::make('impact')->options(['high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')])->required(),
                        TextInput::make('title')->required(),
                        Textarea::make('evidence')->required()->rows(2),
                        Textarea::make('recommendation')->required()->rows(2),
                    ])
                    ->reorderable()
                    ->deletable()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
            ]),
            Section::make(__('File-bound findings'))->schema([
                Repeater::make('file_findings')
                    ->label('')
                    ->schema([
                        TextInput::make('path')->required(),
                        TextInput::make('title')->required(),
                        Textarea::make('evidence')->required()->rows(2),
                        Textarea::make('recommendation')->required()->rows(2),
                        Select::make('severity')
                            ->options(collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [$s->value => ucfirst($s->value)]))
                            ->required(),
                        Select::make('category')
                            ->options([
                                'business_logic' => __('Business logic'),
                                'authorization' => __('Authorization'),
                                'architecture' => __('Architecture'),
                                'security' => __('Security'),
                            ])
                            ->required(),
                        Select::make('effort')->options(['S' => __('Small'), 'M' => __('Medium'), 'L' => __('Large')])->required(),
                        // Preserved but not reviewer-editable — dropping these
                        // on save would silently discard the model's original
                        // line/cross-module attribution.
                        Hidden::make('line'),
                        Hidden::make('related_paths'),
                    ])
                    ->reorderable()
                    ->deletable()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
            ]),
            Section::make(__('Expert section'))->schema([
                Textarea::make('expert_summary')
                    ->label(__('Expert summary'))
                    ->helperText(__('Required before this report can be published.'))
                    ->rows(4),
                Textarea::make('review_notes')
                    ->label(__('Review notes'))
                    ->rows(4),
            ]),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // @phpstan-ignore-next-line property.notFound (report is AuditRequest's hasOne relation; Larastan can't see it through the parent's generic Model return type)
        $payload = $this->getRecord()->report->payload;

        $data['risks'] = $payload['risks'] ?? [];
        $data['file_findings'] = $payload['file_findings'] ?? [];
        $data['expert_summary'] = $payload['expert_review']['expert_summary'] ?? '';
        $data['review_notes'] = $payload['expert_review']['review_notes'] ?? '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // @phpstan-ignore-next-line property.notFound (report is AuditRequest's hasOne relation; Larastan can't see it through the parent's generic Model return type)
        $payload = $record->report->payload;
        $payload['risks'] = $data['risks'];
        $payload['file_findings'] = $data['file_findings'];

        if (trim((string) $data['expert_summary']) !== '' || trim((string) $data['review_notes']) !== '') {
            $payload['expert_review'] = [
                // Filament dehydrates an empty text field to `null`, not `''`
                // (vendor/filament/schemas/src/Components/Concerns/HasState.php,
                // getStateToDehydrate()) — cast back to string so a blank
                // review_notes/expert_summary never breaks the payload
                // contract's is_string() check.
                'expert_summary' => (string) $data['expert_summary'],
                'review_notes' => (string) $data['review_notes'],
                'reviewed_by' => $payload['expert_review']['reviewed_by'] ?? '',
                'reviewed_at' => $payload['expert_review']['reviewed_at'] ?? '',
            ];
        } else {
            unset($payload['expert_review']);
        }

        $validated = ReportPayload::validate($payload, ReportPayload::VERSION);
        // @phpstan-ignore-next-line property.notFound (report is AuditRequest's hasOne relation; Larastan can't see it through the parent's generic Model return type)
        $record->report->update(['payload' => $validated, 'payload_schema_version' => ReportPayload::VERSION]);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish report'))
                ->requiresConfirmation()
                ->modalDescription(__('This sends the report to the customer immediately and cannot be undone.'))
                ->disabled(fn (): bool => trim((string) ($this->data['expert_summary'] ?? '')) === '')
                ->action(function (): void {
                    $this->save(shouldRedirect: false);

                    // @phpstan-ignore-next-line property.notFound (report is AuditRequest's hasOne relation; Larastan can't see it through the parent's generic Model return type)
                    app(AuditReportService::class)->publish($this->getRecord()->report->fresh());

                    Notification::make()->title(__('Report published'))->success()->send();

                    $this->redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }
}
