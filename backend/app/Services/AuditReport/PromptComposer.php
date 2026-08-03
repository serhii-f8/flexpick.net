<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\ConfigService;

class PromptComposer
{
    public const DEFAULT_TEMPLATE = <<<'TEMPLATE'
Repository metrics (JSON):
{metrics}

Problem groups, ranked by severity and count. Narrate each one: what it is,
what it affects, and what fixing it buys the client. Never enumerate individual
findings — one lint error must never become one report item.
{groups}

File excerpts (largest files, truncated):
{excerpts}

Produce the codebase health report.
TEMPLATE;

    public function __construct(
        private ConfigService $configService,
    ) {}

    /**
     * The template in use. A stored override that predates the groups
     * placeholder is NOT used — it would silently produce prompts containing
     * no findings, making the whole scanner platform invisible (spec §7.3).
     */
    public function template(): string
    {
        return $this->storedOverrideIsUsable()
            ? trim((string) $this->configService->get('audit.prompt_template', ''))
            : self::DEFAULT_TEMPLATE;
    }

    public function storedOverrideIsUsable(): bool
    {
        $override = trim((string) $this->configService->get('audit.prompt_template', ''));

        return $override !== '' && $this->templateIsValid($override);
    }

    public function templateIsValid(string $template): bool
    {
        return str_contains($template, '{metrics}')
            && str_contains($template, '{groups}')
            && str_contains($template, '{excerpts}');
    }

    /**
     * @param  list<FindingGroup>  $groups
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function compose(array $metrics, array $groups, array $excerpts, ?string $adminContext = null): string
    {
        return $this->withAdminContext(
            str_replace(
                ['{metrics}', '{groups}', '{excerpts}'],
                [
                    json_encode($metrics, JSON_PRETTY_PRINT),
                    $this->renderGroups($groups),
                    $this->renderExcerpts($excerpts),
                ],
                $this->template(),
            ),
            $adminContext,
        );
    }

    /**
     * The prompt the next run would use — stored metrics if any, excerpts and
     * groups marked as runtime-computed (neither is persisted on the request).
     */
    public function preview(AuditRequest $request): string
    {
        return $this->withAdminContext(
            str_replace(
                ['{metrics}', '{groups}', '{excerpts}'],
                [
                    json_encode($request->metrics ?? ['note' => 'metrics are collected at run time'], JSON_PRETTY_PRINT),
                    "\n[problem groups are computed at run time]\n",
                    "\n[file excerpts are computed at run time]\n",
                ],
                $this->template(),
            ),
            $request->admin_context,
        );
    }

    /** Single append path, shared by compose() and preview() — §18.2 defect. */
    private function withAdminContext(string $prompt, ?string $adminContext): string
    {
        if ($adminContext === null || trim($adminContext) === '') {
            return $prompt;
        }

        return $prompt."\n\nAdditional context from the audit team:\n".trim($adminContext);
    }

    /** @param list<FindingGroup> $groups */
    private function renderGroups(array $groups): string
    {
        if ($groups === []) {
            return "\n[no problem groups were produced for this run]\n";
        }

        $rendered = '';

        foreach ($groups as $group) {
            $locations = implode(', ', array_map(
                fn (array $example): string => $example['path'].($example['line'] !== null ? ':'.$example['line'] : ''),
                $group->examples,
            ));

            $rendered .= sprintf(
                "\n- %s in %s — %d finding(s), severity %s, reported by %s\n  examples: %s\n",
                $group->ruleFamily,
                $group->directory,
                $group->count,
                $group->severity->value,
                implode('+', $group->tools),
                $locations !== '' ? $locations : 'none recorded',
            );
        }

        return $rendered;
    }

    /** @param list<array{path: string, content: string}> $excerpts */
    private function renderExcerpts(array $excerpts): string
    {
        $rendered = '';

        foreach ($excerpts as $excerpt) {
            $rendered .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        return $rendered;
    }
}
