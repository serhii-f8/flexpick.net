<?php

namespace App\Models;

use App\Constants\AuditAiStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Anthropic call, billed or attempted.
 *
 * The token columns on `audit_requests` cannot answer "what did we spend?":
 * they are written once, at the end of a successful run, so a delivery failure
 * discards them and a re-run overwrites them. A pipeline that retries by
 * re-running from the clone bills every attempt, so spend has to be recorded
 * per call, as the call returns, independently of whether the report it feeds
 * ever ships.
 */
class AuditAiCall extends Model
{
    use HasFactory;

    public const OUTCOME_OK = 'ok';

    public const OUTCOME_FAILED = 'failed';

    /** Observed by the pipeline as the call returned. */
    public const SOURCE_PIPELINE = 'pipeline';

    /** Transcribed from the provider's console after the fact. */
    public const SOURCE_BACKFILL = 'backfill';

    protected $fillable = [
        'audit_request_id', 'stage', 'model', 'outcome', 'source',
        'input_tokens', 'output_tokens', 'failure_reason', 'duration_ms',
    ];

    protected $casts = [
        'stage' => AuditAiStage::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<AuditRequest, $this>
     */
    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }

    /**
     * List-price cost of this call in USD, or null when the price of the model
     * that served it is not configured.
     *
     * Null rather than 0.0 on purpose: an unpriced model is an unknown cost,
     * and summing unknowns as zero is how a spend report quietly under-reports.
     * Callers that total these must decide what to do with the nulls.
     */
    public function costUsd(): ?float
    {
        $rates = config('audit.model_pricing.'.$this->model);

        if (! is_array($rates) || $this->input_tokens === null || $this->output_tokens === null) {
            return null;
        }

        return ($this->input_tokens * (float) $rates['input']
            + $this->output_tokens * (float) $rates['output']) / 1_000_000;
    }

    /**
     * Calls whose cost is unknown — either the model is unpriced or the call
     * never returned a usage figure. A spend total is only trustworthy read
     * alongside this count.
     *
     * @param  Builder<AuditAiCall>  $query
     * @return Builder<AuditAiCall>
     */
    public function scopeUnpriced(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('input_tokens')
                    ->orWhereNull('output_tokens')
                    ->orWhereNotIn('model', array_keys((array) config('audit.model_pricing', [])));
            });
    }
}
