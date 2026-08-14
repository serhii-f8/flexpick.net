<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\ClaudeDeepReviewer;
use Tests\Feature\FeatureTest;

/**
 * The structured-output schema is only ever exercised against the live API, so
 * a keyword the API rejects fails EVERY deep review with a 400 and degrades the
 * section for every paying customer — silently, because the pipeline catches it.
 * That is exactly what shipped: `maxItems` is not in Anthropic's supported
 * subset ("For 'array' type, property 'maxItems' is not supported").
 */
class ClaudeDeepReviewerSchemaTest extends FeatureTest
{
    public function test_the_schema_uses_no_keyword_the_api_rejects(): void
    {
        $this->assertSame([], $this->unsupportedKeywordsIn($this->invokePrivate('schema')));
    }

    public function test_the_finding_cap_is_enforced_after_decoding(): void
    {
        config()->set('audit.deep_review.max_findings', 3);

        $findings = array_map(
            static fn (int $i): array => ['path' => "app/File{$i}.php", 'title' => "Finding {$i}"],
            range(1, 10),
        );

        $capped = $this->invokePrivate('capFindings', $findings);

        $this->assertCount(3, $capped);
        $this->assertSame('app/File1.php', $capped[0]['path']);
    }

    public function test_the_cap_leaves_a_shorter_list_untouched(): void
    {
        config()->set('audit.deep_review.max_findings', 40);

        $this->assertCount(2, $this->invokePrivate('capFindings', [['path' => 'a'], ['path' => 'b']]));
    }

    /**
     * The model can no longer be bound by the schema, so the cap has to reach
     * it as an instruction — otherwise only the post-hoc slice enforces it and
     * the tokens for the discarded findings are paid for and thrown away.
     */
    public function test_the_system_prompt_states_the_cap(): void
    {
        config()->set('audit.deep_review.max_findings', 40);

        $this->assertStringContainsString('40', $this->invokePrivate('systemPrompt'));
    }

    /**
     * @param  array<mixed>  $schema
     * @return list<string>
     */
    private function unsupportedKeywordsIn(array $schema, string $path = 'schema'): array
    {
        $unsupported = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, ['maxItems', 'minItems'], true)) {
                $unsupported[] = $path.'.'.$key;
            }

            if (is_array($value)) {
                $unsupported = [...$unsupported, ...$this->unsupportedKeywordsIn($value, $path.'.'.$key)];
            }
        }

        return $unsupported;
    }

    private function invokePrivate(string $method, mixed ...$arguments): mixed
    {
        $reviewer = app(ClaudeDeepReviewer::class);
        $reflection = new \ReflectionMethod($reviewer, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($reviewer, ...$arguments);
    }
}
