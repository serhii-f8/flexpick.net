<?php

namespace Tests\Feature\Services\AuditReport;

use Anthropic\Client;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AnthropicClientFactory;
use Tests\Feature\FeatureTest;

/**
 * The deep review sends ~150k tokens and asks for 16k back, so the model is
 * silent for minutes. Symfony HttpClient — the PSR-18 transporter the SDK
 * discovers — treats 60 seconds of silence as a dead connection by default, and
 * the SDK's own timeout option is advisory and never applied. Every deep review
 * therefore died as an APIConnectionException; the shorter tier-1 call, at ~45s,
 * happened to fit under the limit and hid the problem.
 */
class AnthropicClientFactoryTest extends FeatureTest
{
    public function test_it_builds_a_client(): void
    {
        $this->assertInstanceOf(Client::class, app(AnthropicClientFactory::class)->make());
    }

    public function test_the_idle_timeout_clears_the_transport_default(): void
    {
        $options = app(AnthropicClientFactory::class)->httpOptions();

        $this->assertSame((float) config('services.anthropic.timeout'), $options['timeout']);
        $this->assertGreaterThan(
            60.0,
            $options['timeout'],
            '60s is the transport default that broke the deep review; the configured value must exceed it.',
        );
    }

    /**
     * A model call that outlives the job waiting on it cannot be retried or
     * reported — the worker kills the run mid-flight instead.
     */
    public function test_the_request_ceiling_stays_inside_the_job_timeout(): void
    {
        $options = app(AnthropicClientFactory::class)->httpOptions();

        $this->assertGreaterThanOrEqual($options['timeout'], $options['max_duration']);
        $this->assertLessThan(
            (new GenerateAuditReport(new AuditRequest))->timeout,
            $options['max_duration'],
        );
    }
}
