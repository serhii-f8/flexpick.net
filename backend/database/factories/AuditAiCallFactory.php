<?php

namespace Database\Factories;

use App\Constants\AuditAiStage;
use App\Models\AuditAiCall;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditAiCallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stage' => AuditAiStage::ANALYSIS->value,
            'model' => (string) config('services.anthropic.model'),
            'outcome' => AuditAiCall::OUTCOME_OK,
            'input_tokens' => 133_701,
            'output_tokens' => 3_314,
            'duration_ms' => 45_000,
        ];
    }

    public function deepReview(): static
    {
        return $this->state(fn () => [
            'stage' => AuditAiStage::DEEP_REVIEW->value,
            'input_tokens' => 178_399,
            'output_tokens' => 9_198,
        ]);
    }

    /** A call that never returned: attempted, probably billed, unsizeable. */
    public function failed(): static
    {
        return $this->state(fn () => [
            'outcome' => AuditAiCall::OUTCOME_FAILED,
            'input_tokens' => null,
            'output_tokens' => null,
            'failure_reason' => 'Anthropic\\Core\\Exceptions\\APIConnectionException',
        ]);
    }
}
