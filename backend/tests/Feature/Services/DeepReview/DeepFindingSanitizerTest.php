<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\DeepFindingSanitizer;
use Tests\Feature\FeatureTest;

class DeepFindingSanitizerTest extends FeatureTest
{
    private function finding(string $path, array $related = []): array
    {
        return [
            'path' => $path,
            'line' => 10,
            'title' => 'A finding',
            'severity' => 'high',
            'category' => 'authorization',
            'evidence' => 'evidence',
            'recommendation' => 'fix it',
            'effort' => 'M',
            'related_paths' => $related,
        ];
    }

    public function test_findings_on_reviewed_files_survive(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php'],
        );

        $this->assertCount(1, $result['findings']);
        $this->assertSame(0, $result['dropped']);
    }

    public function test_a_finding_on_a_file_that_was_never_sent_is_dropped(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Never/Sent.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Never/Sent.php'],
        );

        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['dropped']);
    }

    public function test_related_paths_outside_the_inventory_are_stripped(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php', ['app/Services/Billing.php', 'app/Imaginary.php'])],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Services/Billing.php'],
        );

        $this->assertSame(['app/Services/Billing.php'], $result['findings'][0]['related_paths']);
        $this->assertSame(1, $result['strippedRelated']);
    }

    public function test_a_related_path_need_not_have_been_reviewed(): void
    {
        // Cross-module findings reference files the model saw referenced but
        // was not sent; those are legitimate as long as the file exists.
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Auth/Guard.php', ['app/Services/Billing.php'])],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php', 'app/Services/Billing.php'],
        );

        $this->assertSame(['app/Services/Billing.php'], $result['findings'][0]['related_paths']);
        $this->assertSame(0, $result['strippedRelated']);
    }

    public function test_the_findings_list_is_reindexed(): void
    {
        $result = app(DeepFindingSanitizer::class)->sanitize(
            [$this->finding('app/Never/Sent.php'), $this->finding('app/Auth/Guard.php')],
            ['app/Auth/Guard.php'],
            ['app/Auth/Guard.php'],
        );

        $this->assertArrayHasKey(0, $result['findings']);
        $this->assertCount(1, $result['findings']);
    }
}
