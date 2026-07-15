<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Services\ConfigService;

class PromptComposer
{
    public const DEFAULT_TEMPLATE = <<<'TEMPLATE'
Repository metrics (JSON):
{metrics}

File excerpts (largest files, truncated):
{excerpts}

Produce the codebase health report.
TEMPLATE;

    public function __construct(
        private ConfigService $configService,
    ) {}

    public function template(): string
    {
        $override = (string) $this->configService->get('audit.prompt_template', '');

        return trim($override) !== '' ? $override : self::DEFAULT_TEMPLATE;
    }

    public function templateIsValid(string $template): bool
    {
        return str_contains($template, '{metrics}') && str_contains($template, '{excerpts}');
    }

    /**
     * @param  array<int, array{path: string, content: string}>  $excerpts
     */
    public function compose(array $metrics, array $excerpts, ?string $adminContext = null): string
    {
        $excerptText = '';
        foreach ($excerpts as $excerpt) {
            $excerptText .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        $prompt = str_replace(
            ['{metrics}', '{excerpts}'],
            [json_encode($metrics, JSON_PRETTY_PRINT), $excerptText],
            $this->template(),
        );

        if ($adminContext !== null && trim($adminContext) !== '') {
            $prompt .= "\n\nAdditional context from the audit team:\n".trim($adminContext);
        }

        return $prompt;
    }

    /**
     * The prompt the next run would use — stored metrics if any, excerpts marked
     * as runtime-computed (they are never persisted).
     */
    public function preview(AuditRequest $request): string
    {
        $metrics = $request->metrics ?? ['note' => 'metrics are collected at run time'];

        $prompt = str_replace(
            ['{metrics}', '{excerpts}'],
            [json_encode($metrics, JSON_PRETTY_PRINT), "\n[file excerpts are computed at run time]\n"],
            $this->template(),
        );

        if ($request->admin_context !== null && trim($request->admin_context) !== '') {
            $prompt .= "\n\nAdditional context from the audit team:\n".trim($request->admin_context);
        }

        return $prompt;
    }
}
