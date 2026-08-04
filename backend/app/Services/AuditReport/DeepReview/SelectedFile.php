<?php

namespace App\Services\AuditReport\DeepReview;

final readonly class SelectedFile
{
    /**
     * @param  array<string, array{raw: float, normalized: float}>  $signals
     */
    public function __construct(
        public string $path,
        public int $rank,
        public float $score,
        public array $signals,
        public string $content,
        public int $estimatedTokens,
    ) {}

    /**
     * Persisted form. Content is deliberately absent: the selection log lives
     * on the audit request forever, and storing source there would defeat the
     * point of filtering secrets out of what we transmit.
     *
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'path' => $this->path,
            'rank' => $this->rank,
            'score' => round($this->score, 4),
            'signals' => $this->signals,
            'estimated_tokens' => $this->estimatedTokens,
        ];
    }
}
