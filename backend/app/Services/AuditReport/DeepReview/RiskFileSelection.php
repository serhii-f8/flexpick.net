<?php

namespace App\Services\AuditReport\DeepReview;

final readonly class RiskFileSelection
{
    /** @param list<SelectedFile> $files */
    public function __construct(
        public array $files,
        public int $candidatesConsidered,
        public int $selectedBeforeBudget,
        public bool $truncated,
        public bool $belowFloor,
        public int $estimatedInputTokens,
        public int $fileBytesUsed,
        public int $selectionVersion,
    ) {}

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(fn (SelectedFile $file): string => $file->path, $this->files);
    }

    /** @return array<string, mixed> */
    public function toLogArray(): array
    {
        return [
            'selection_version' => $this->selectionVersion,
            'candidates_considered' => $this->candidatesConsidered,
            'selected_before_budget' => $this->selectedBeforeBudget,
            'files_reviewed' => count($this->files),
            'truncated' => $this->truncated,
            'below_floor' => $this->belowFloor,
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'file_bytes_used' => $this->fileBytesUsed,
            'files' => array_map(fn (SelectedFile $file): array => $file->toLogArray(), $this->files),
        ];
    }
}
