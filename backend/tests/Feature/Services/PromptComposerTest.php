<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Tests\Feature\FeatureTest;

class PromptComposerTest extends FeatureTest
{
    public function test_uses_built_in_template_by_default(): void
    {
        $composer = app(PromptComposer::class);

        $prompt = $composer->compose(['files' => 3], [['path' => 'a.php', 'content' => 'echo 1;']]);

        $this->assertStringContainsString('Repository metrics (JSON):', $prompt);
        $this->assertStringContainsString('"files": 3', $prompt);
        $this->assertStringContainsString('===== a.php =====', $prompt);
        $this->assertStringContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_setting_overrides_template(): void
    {
        app(ConfigService::class)->set('audit.prompt_template', "CUSTOM HEADER\n{metrics}\nMIDDLE\n{excerpts}\nCUSTOM FOOTER");

        $prompt = app(PromptComposer::class)->compose(['files' => 1], []);

        $this->assertStringContainsString('CUSTOM HEADER', $prompt);
        $this->assertStringContainsString('CUSTOM FOOTER', $prompt);
        $this->assertStringNotContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_admin_context_is_appended(): void
    {
        $prompt = app(PromptComposer::class)->compose(['files' => 1], [], 'Pay attention to the auth module.');

        $this->assertStringContainsString('Additional context from the audit team:', $prompt);
        $this->assertStringContainsString('Pay attention to the auth module.', $prompt);
    }

    public function test_template_validation_requires_both_placeholders(): void
    {
        $composer = app(PromptComposer::class);

        $this->assertTrue($composer->templateIsValid('x {metrics} y {excerpts} z'));
        $this->assertFalse($composer->templateIsValid('missing everything'));
        $this->assertFalse($composer->templateIsValid('only {metrics}'));
    }

    public function test_preview_uses_stored_metrics_and_marks_excerpts(): void
    {
        $request = AuditRequest::factory()->create([
            'metrics' => ['files' => 42],
            'admin_context' => 'Preview context.',
        ]);

        $preview = app(PromptComposer::class)->preview($request);

        $this->assertStringContainsString('"files": 42', $preview);
        $this->assertStringContainsString('[file excerpts are computed at run time]', $preview);
        $this->assertStringContainsString('Preview context.', $preview);
    }
}
