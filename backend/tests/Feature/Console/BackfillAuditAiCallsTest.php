<?php

namespace Tests\Feature\Console;

use App\Constants\AuditTier;
use App\Models\AuditAiCall;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class BackfillAuditAiCallsTest extends FeatureTest
{
    /** The deep_ai request that absorbed most of the 08-14 spend. */
    private const DEEP_AI_UUID = '01a00111-d167-7350-b077-1ec90f77b25d';

    private const UUIDS = [
        '019ffa6f-1bfa-709b-970a-ba8e1d57f53a' => AuditTier::DIAGNOSTIC,
        '019fffbb-76e1-717f-8912-dd38c6e358b4' => AuditTier::DIAGNOSTIC,
        self::DEEP_AI_UUID => AuditTier::DEEP_AI,
        '01a00115-8ca8-70e6-b992-038ed1e033df' => AuditTier::AUTOMATED,
        '01a00685-6376-73e8-897d-aa786eb07bbf' => AuditTier::EXPERT,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        AuditAiCall::query()->delete();
        AuditRequest::query()->whereIn('uuid', array_keys(self::UUIDS))->delete();

        config()->set('audit.model_pricing', [
            'claude-opus-4-8' => ['input' => 5.0, 'output' => 25.0],
        ]);
    }

    private function seedRequests(): void
    {
        foreach (self::UUIDS as $uuid => $tier) {
            AuditRequest::factory()->create(['uuid' => $uuid, 'tier' => $tier->value]);
        }
    }

    private function ledgerSpend(): float
    {
        return AuditAiCall::all()->sum(fn (AuditAiCall $call): float => $call->costUsd() ?? 0.0);
    }

    public function test_it_reconstructs_the_window_and_reconciles_with_the_console(): void
    {
        $this->seedRequests();

        $this->artisan('app:backfill-audit-ai-calls')
            ->expectsOutputToContain('Wrote 20 call(s) worth $13.84')
            ->assertSuccessful();

        $this->assertSame(20, AuditAiCall::query()->count());
        $this->assertEqualsWithDelta(13.84, $this->ledgerSpend(), 0.005);

        // Every row is marked as testimony, not measurement.
        $this->assertSame(20, AuditAiCall::query()->where('source', AuditAiCall::SOURCE_BACKFILL)->count());
    }

    public function test_spend_lands_on_the_day_it_was_billed(): void
    {
        $this->seedRequests();
        $this->artisan('app:backfill-audit-ai-calls')->assertSuccessful();

        $byDay = AuditAiCall::all()
            ->groupBy(fn (AuditAiCall $call): string => $call->created_at->toDateString())
            ->map(fn ($calls): float => $calls->sum(fn (AuditAiCall $c): float => $c->costUsd() ?? 0.0));

        // The console's three days, less the $0.52 of unattributable probes
        // that 08-14 also carried.
        $this->assertEqualsWithDelta(0.19, $byDay['2026-08-13'], 0.005);
        $this->assertEqualsWithDelta(9.74, $byDay['2026-08-14'], 0.005);
        $this->assertEqualsWithDelta(3.90, $byDay['2026-08-15'], 0.005);
    }

    public function test_the_double_billed_deep_review_is_recorded_as_two_calls(): void
    {
        $this->seedRequests();
        $this->artisan('app:backfill-audit-ai-calls')->assertSuccessful();

        // One logical call, retried by the SDK after the idle timeout, billed
        // twice — the case the live recorder cannot see and only the console
        // could reveal.
        $retries = AuditAiCall::query()
            ->where('input_tokens', 180255)
            ->get();

        $this->assertCount(2, $retries);
        $this->assertEqualsWithDelta(2.089, $retries->sum(fn (AuditAiCall $c): float => $c->costUsd()), 0.001);
    }

    public function test_it_can_be_run_twice_without_duplicating_spend(): void
    {
        $this->seedRequests();

        $this->artisan('app:backfill-audit-ai-calls')->assertSuccessful();
        $this->artisan('app:backfill-audit-ai-calls')
            ->expectsOutputToContain('Wrote 0 call(s)')
            ->assertSuccessful();

        $this->assertSame(20, AuditAiCall::query()->count());
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->seedRequests();

        $this->artisan('app:backfill-audit-ai-calls', ['--dry-run' => true])
            ->expectsOutputToContain('Would write 20 call(s)')
            ->assertSuccessful();

        $this->assertSame(0, AuditAiCall::query()->count());
    }

    public function test_it_writes_nothing_against_a_database_it_does_not_recognise(): void
    {
        // No seeded requests: on any other database these uuids do not exist,
        // and attributing $14 to whatever holds those ids would be worse than
        // recording nothing.
        $this->artisan('app:backfill-audit-ai-calls')
            ->expectsOutputToContain('Wrote 0 call(s)')
            ->expectsOutputToContain('This backfill targets one database.')
            ->assertSuccessful();

        $this->assertSame(0, AuditAiCall::query()->count());
    }

    public function test_it_always_reports_the_residue_it_could_not_attribute(): void
    {
        $this->seedRequests();

        $this->artisan('app:backfill-audit-ai-calls')
            ->expectsOutputToContain('Not backfilled: $0.52')
            ->assertSuccessful();
    }

    public function test_pipeline_observed_rows_are_never_displaced_by_transcribed_ones(): void
    {
        $this->seedRequests();
        $request = AuditRequest::query()->where('uuid', self::DEEP_AI_UUID)->sole();

        // The pipeline already saw one of these calls.
        AuditAiCall::factory()->for($request)->create([
            'model' => 'claude-opus-4-8',
            'input_tokens' => 133701,
            'output_tokens' => 3171,
            'created_at' => '2026-08-15 17:47:52',
        ]);

        $this->artisan('app:backfill-audit-ai-calls')->assertSuccessful();

        $this->assertSame(20, AuditAiCall::query()->count());
        $this->assertSame(1, AuditAiCall::query()->where('source', AuditAiCall::SOURCE_PIPELINE)->count());
    }
}
