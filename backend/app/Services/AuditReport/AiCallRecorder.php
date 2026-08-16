<?php

namespace App\Services\AuditReport;

use App\Constants\AuditAiStage;
use App\Models\AuditAiCall;
use App\Models\AuditRequest;

/**
 * Writes the per-call spend ledger.
 *
 * Kept out of the analyzers so they stay transport objects with no database
 * dependency, and out of the pipeline's own update() calls so a recording
 * failure can never take down the run that was billed — the ledger is
 * evidence, not a step the report depends on.
 */
class AiCallRecorder
{
    /**
     * A call that returned. Record it before anything downstream can fail:
     * the tokens are already billed by the time the response is in hand, and
     * a sanitizer, validator, PDF renderer, or mailer throwing afterwards
     * must not erase that.
     */
    public function recordSuccess(
        AuditRequest $request,
        AuditAiStage $stage,
        int $inputTokens,
        int $outputTokens,
        int $durationMs,
    ): AuditAiCall {
        return AuditAiCall::create([
            'audit_request_id' => $request->id,
            'stage' => $stage->value,
            'model' => (string) config('services.anthropic.model'),
            'outcome' => AuditAiCall::OUTCOME_OK,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * A call that did not return. Tokens are unknown — deliberately not zero:
     * a timeout means the client gave up, not that the model stopped
     * generating, and such a call is normally billed in full. A row with null
     * tokens says "spend happened here and we cannot size it", which is what
     * makes the gap against the provider's own invoice findable.
     *
     * @param  string  $reason  Exception class only, never its message — the
     *                          same §5.4 convention the pipeline log follows,
     *                          since provider errors can echo customer source.
     */
    public function recordFailure(
        AuditRequest $request,
        AuditAiStage $stage,
        string $reason,
        int $durationMs,
    ): AuditAiCall {
        return AuditAiCall::create([
            'audit_request_id' => $request->id,
            'stage' => $stage->value,
            'model' => (string) config('services.anthropic.model'),
            'outcome' => AuditAiCall::OUTCOME_FAILED,
            'failure_reason' => mb_substr($reason, 0, 255),
            'duration_ms' => $durationMs,
        ]);
    }
}
