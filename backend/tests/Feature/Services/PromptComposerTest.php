<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use App\Models\Config;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\PromptComposer;
use App\Services\ConfigService;
use Tests\Feature\FeatureTest;

class PromptComposerTest extends FeatureTest
{
    protected function tearDown(): void
    {
        // This suite has no per-test DB reset (FeatureTest::migrate:fresh runs
        // once). ConfigService::set() persists a Config row, which would
        // otherwise leak the override from one test into every test that
        // runs after it in the same process, regardless of declared order.
        Config::where('key', 'audit.prompt_template')->delete();

        parent::tearDown();
    }

    public function test_uses_built_in_template_by_default(): void
    {
        $composer = app(PromptComposer::class);

        $prompt = $composer->compose(['files' => 3], [], [['path' => 'a.php', 'content' => 'echo 1;']]);

        $this->assertStringContainsString('Repository metrics (JSON):', $prompt);
        $this->assertStringContainsString('"files": 3', $prompt);
        $this->assertStringContainsString('===== a.php =====', $prompt);
        $this->assertStringContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_setting_overrides_template(): void
    {
        app(ConfigService::class)->set(
            'audit.prompt_template',
            "CUSTOM HEADER\n{metrics}\nMIDDLE\n{groups}\n{excerpts}\nCUSTOM FOOTER",
        );

        $prompt = app(PromptComposer::class)->compose(['files' => 1], [], []);

        $this->assertStringContainsString('CUSTOM HEADER', $prompt);
        $this->assertStringContainsString('CUSTOM FOOTER', $prompt);
        $this->assertStringNotContainsString('Produce the codebase health report.', $prompt);
    }

    public function test_admin_context_is_appended(): void
    {
        $prompt = app(PromptComposer::class)->compose(['files' => 1], [], [], 'Pay attention to the auth module.');

        $this->assertStringContainsString('Additional context from the audit team:', $prompt);
        $this->assertStringContainsString('Pay attention to the auth module.', $prompt);
    }

    public function test_template_validation_requires_all_three_placeholders(): void
    {
        $composer = app(PromptComposer::class);

        $this->assertTrue($composer->templateIsValid('x {metrics} y {groups} z {excerpts} w'));
        $this->assertFalse($composer->templateIsValid('missing everything'));
        $this->assertFalse($composer->templateIsValid('only {metrics}'));
        $this->assertFalse($composer->templateIsValid('{metrics} and {excerpts} only'));
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

    public function test_default_template_carries_all_three_placeholders(): void
    {
        $template = PromptComposer::DEFAULT_TEMPLATE;

        $this->assertStringContainsString('{metrics}', $template);
        $this->assertStringContainsString('{groups}', $template);
        $this->assertStringContainsString('{excerpts}', $template);
    }

    public function test_a_template_without_groups_is_invalid(): void
    {
        $composer = app(PromptComposer::class);

        $this->assertFalse($composer->templateIsValid('{metrics} and {excerpts} only'));
        $this->assertTrue($composer->templateIsValid('{metrics} {groups} {excerpts}'));
    }

    public function test_a_stored_override_lacking_groups_is_not_used(): void
    {
        // A pre-Phase-11 override would otherwise keep validating and silently
        // produce prompts with no findings at all (spec §7.3).
        app(ConfigService::class)->set('audit.prompt_template', 'Legacy: {metrics} {excerpts}');

        $this->assertFalse(app(PromptComposer::class)->storedOverrideIsUsable());
        $this->assertSame(PromptComposer::DEFAULT_TEMPLATE, app(PromptComposer::class)->template());
    }

    public function test_a_valid_stored_override_is_still_honoured(): void
    {
        app(ConfigService::class)->set('audit.prompt_template', 'Custom {metrics} {groups} {excerpts}');

        $this->assertTrue(app(PromptComposer::class)->storedOverrideIsUsable());
        $this->assertStringContainsString('Custom', app(PromptComposer::class)->template());
    }

    public function test_composes_groups_into_the_prompt(): void
    {
        $prompt = app(PromptComposer::class)->compose(
            ['files_total' => 10],
            [new FindingGroup('php.injection', 'app/Http', Severity::HIGH, 37, 1480,
                [['path' => 'app/Http/UserController.php', 'line' => 42]], ['semgrep'], 'security_hygiene')],
            [],
        );

        $this->assertStringContainsString('php.injection', $prompt);
        $this->assertStringContainsString('app/Http', $prompt);
        $this->assertStringContainsString('37', $prompt);
    }

    public function test_group_examples_contribute_paths_but_never_content(): void
    {
        $prompt = app(PromptComposer::class)->compose(
            [],
            [new FindingGroup('secrets.credential', 'config', Severity::CRITICAL, 1, 100,
                [['path' => 'config/services.php', 'line' => 17]], ['gitleaks'], 'security_hygiene')],
            [],
        );

        $this->assertStringContainsString('config/services.php', $prompt);
        $this->assertStringContainsString(':17', $prompt);
    }

    public function test_manifest_parse_error_is_visible_in_composed_prompt(): void
    {
        $metrics = [
            'manifests' => [
                'composer.json' => [
                    'dependencies' => 0,
                    'dev_dependencies' => 0,
                    'lockfile' => true,
                    'parse_error' => true,
                ],
            ],
        ];

        $prompt = app(PromptComposer::class)->compose($metrics, [], []);

        $this->assertStringContainsString('"parse_error": true', $prompt);
    }

    public function test_admin_context_is_appended_identically_by_compose_and_preview(): void
    {
        // §18.2 defect: the append block was duplicated verbatim between the two.
        $request = AuditRequest::factory()->create([
            'admin_context' => 'The client reports intermittent 500s at checkout.',
            'metrics' => ['files_total' => 10],
        ]);

        $composed = app(PromptComposer::class)->compose(['files_total' => 10], [], [], $request->admin_context);
        $previewed = app(PromptComposer::class)->preview($request);

        $this->assertStringContainsString('The client reports intermittent 500s at checkout.', $composed);
        $this->assertStringContainsString('The client reports intermittent 500s at checkout.', $previewed);
    }
}
