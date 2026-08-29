<?php

namespace App\Models;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditRequest extends Model
{
    use HasFactory, HasUuids;

    public const TRIAGE_IN_FLIGHT = 'in_flight';

    public const TRIAGE_NEEDS_MANUAL_ACTION = 'needs_manual_action';

    public const TRIAGE_FAILED = 'failed';

    public const TRIAGE_EXPERT_REVIEW = 'expert_review';

    public const TRIAGE_TERMINAL = 'terminal';

    protected $fillable = [
        'name', 'email', 'repo_url', 'branch', 'message', 'status', 'failure_reason', 'meta', 'metrics',
        'email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'funding', 'source', 'tier', 'user_id', 'prepaid',
        'manually_paid', 'admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at', 'scanner_runs',
        'ai_input_tokens', 'ai_output_tokens', 'scanner_ms', 'repo_size_kb',
        'risk_files', 'deep_review_input_tokens', 'deep_review_output_tokens', 'deep_review_ms',
    ];

    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
        'email_verified_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'consented_at' => 'datetime',
        'free_run' => 'boolean',
        'prepaid' => 'boolean',
        'manually_paid' => 'boolean',
        'pipeline_log' => 'array',
        'scanner_runs' => 'array',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
        'tier' => AuditTier::class,
        'funding' => AuditFunding::class,
        'ai_input_tokens' => 'integer',
        'ai_output_tokens' => 'integer',
        'scanner_ms' => 'integer',
        'repo_size_kb' => 'integer',
        'risk_files' => 'array',
        'deep_review_input_tokens' => 'integer',
        'deep_review_output_tokens' => 'integer',
        'deep_review_ms' => 'integer',
    ];

    /**
     * Mirror the migration's column default at the PHP layer. Without it a
     * request created but never reloaded carries a null tier, and every
     * consumer that resolves a profile from it (AuditPipeline) fails.
     */
    protected $attributes = [
        'tier' => AuditTier::DIAGNOSTIC->value,
    ];

    /**
     * All audits owned by the given user: linked by id, or submitted with
     * their email before they registered.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)->orWhere('email', $user->email);
        });
    }

    /**
     * Queued past the queue threshold, or analyzing past the analyzing one.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeStuck(Builder $query): Builder
    {
        $queuedCutoff = now()->subMinutes((int) config('health.flexpick.oldest_queued_minutes'));
        $analyzingCutoff = now()->subMinutes((int) config('health.flexpick.oldest_analyzing_minutes'));

        return $query->where(function (Builder $query) use ($queuedCutoff, $analyzingCutoff): void {
            $query
                ->where(function (Builder $query) use ($queuedCutoff): void {
                    $query
                        ->whereIn('status', [AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value])
                        ->where('created_at', '<', $queuedCutoff);
                })
                ->orWhere(function (Builder $query) use ($analyzingCutoff): void {
                    // COALESCE, not a plain column compare: a pipeline that died
                    // before stamping analysis_started_at leaves it null, and a
                    // null would drop the row out of the comparison entirely --
                    // hiding the very records most likely to be wedged.
                    $query
                        ->where('status', AuditRequestStatus::ANALYZING->value)
                        ->whereRaw('COALESCE(analysis_started_at, updated_at) < ?', [$analyzingCutoff]);
                });
        });
    }

    /**
     * Waiting on a human, not on the pipeline.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeNeedsManualAction(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AuditRequestStatus::NEEDS_FOLLOWUP->value,
            AuditRequestStatus::AWAITING_ACCESS->value,
            AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);
    }

    /**
     * Expert-tier reports held past the delivery promise.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeBreachingExpertReviewSla(Builder $query): Builder
    {
        return $query
            ->where('tier', AuditTier::EXPERT->value)
            ->where('status', AuditRequestStatus::EXPERT_REVIEW->value)
            ->where('analysis_completed_at', '<', now()->subHours((int) config('audit.expert_review_sla_hours')));
    }

    /**
     * Every status, classified for operator triage. A test asserts this map is
     * exhaustive, so adding an AuditRequestStatus case without classifying it
     * fails the suite rather than quietly vanishing from the admin panel.
     *
     * @return array<string, string>
     */
    public static function statusTriage(): array
    {
        return [
            AuditRequestStatus::NEW->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::QUEUED->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::ANALYZING->value => self::TRIAGE_IN_FLIGHT,
            AuditRequestStatus::PENDING_VERIFICATION->value => self::TRIAGE_IN_FLIGHT,

            AuditRequestStatus::NEEDS_FOLLOWUP->value => self::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequestStatus::AWAITING_ACCESS->value => self::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequestStatus::AWAITING_PAYMENT->value => self::TRIAGE_NEEDS_MANUAL_ACTION,

            AuditRequestStatus::FAILED->value => self::TRIAGE_FAILED,
            AuditRequestStatus::EXPERT_REVIEW->value => self::TRIAGE_EXPERT_REVIEW,

            AuditRequestStatus::REPORT_READY->value => self::TRIAGE_TERMINAL,
            AuditRequestStatus::SENT->value => self::TRIAGE_TERMINAL,
            AuditRequestStatus::HANDLED->value => self::TRIAGE_TERMINAL,
        ];
    }

    /**
     * True while the request's report is held for a human reviewer -- the
     * report row already exists with the raw, un-reviewed AI payload, so
     * every consumer that might expose that payload gates on this.
     */
    public function isHeldForExpertReview(): bool
    {
        return $this->status === AuditRequestStatus::EXPERT_REVIEW->value;
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasOne<AuditReport, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(AuditReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AuditFindingGroup, $this> */
    public function findingGroups(): HasMany
    {
        return $this->hasMany(AuditFindingGroup::class)->orderByDesc('score');
    }

    /**
     * @return HasMany<AuditEmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(AuditEmailLog::class)->latest('sent_at');
    }

    /**
     * Every model call this request has made, across all attempts — the
     * ai_*_tokens columns describe only the latest one.
     *
     * @return HasMany<AuditAiCall, $this>
     */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AuditAiCall::class)->oldest();
    }

    public function appendPipelineLog(string $step, string $message): void
    {
        $log = $this->pipeline_log ?? [];
        $log[] = ['step' => $step, 'message' => $message, 'at' => now()->toIso8601String()];

        $this->forceFill(['pipeline_log' => $log])->save();
    }
}
