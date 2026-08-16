<?php

namespace App\Console\Commands;

use App\Constants\AuditAiStage;
use App\Models\AuditAiCall;
use App\Models\AuditRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-off: reconstruct the 2026-08-13..15 spend the ledger was added too late
 * to observe.
 *
 * Deliberately a command and not a migration. The rows below are transcribed
 * from one Anthropic console view and attributed to one database's requests —
 * running them anywhere else would invent spend. Requests are matched by uuid
 * rather than id so a mismatched database silently skips instead of writing
 * $14 of someone else's money onto whatever happens to hold id 25.
 */
class BackfillAuditAiCalls extends Command
{
    protected $signature = 'app:backfill-audit-ai-calls {--dry-run : Report what would be written and change nothing}';

    protected $description = 'Reconstruct the pre-ledger AI spend of 2026-08-13..15 from the provider console log';

    /**
     * The model that served every call in this window.
     *
     * Pinned rather than read from config, because config names the model in
     * use TODAY: re-pointing AUDIT_AI_MODEL must not silently reprice history.
     */
    private const MODEL = 'claude-opus-4-8';

    /**
     * Every billed pipeline call in the window, in order.
     *
     * Attribution comes from matching each console row against the audit's own
     * pipeline_log: tier-1 calls line up with `analyzed` steps and deep reviews
     * with `deep_review`/`deep_review_degraded`, and the input-token counts
     * separate the tiers unambiguously (24,511 diagnostic vs ~133,5xx for the
     * rest). Timestamps are the pipeline_log's, in UTC.
     *
     * Two things are deliberately absent:
     *
     * - The three deep reviews that died as BadRequestException on 08-14 have
     *   no console row at all. The API rejected them before generating, so
     *   they cost nothing, and a null-token row would misreport them as spend
     *   of unknown size. The pipeline_log still records the attempts.
     * - Four calls in the console (49, 49, 622 and 628 input tokens, $0.52
     *   total) are three orders of magnitude too small for any tier prompt and
     *   belong to no request — manual probes made while debugging the timeout.
     *   Out-of-band spend is real but not the pipeline's, and this ledger has
     *   nowhere honest to put it.
     *
     * @var list<array{uuid: string, stage: AuditAiStage, in: int, out: int, at: string}>
     */
    private const CALLS = [
        // 08-13 — the one clean run before any of this went wrong.
        ['uuid' => '019ffa6f-1bfa-709b-970a-ba8e1d57f53a', 'stage' => AuditAiStage::ANALYSIS, 'in' => 24496, 'out' => 2684, 'at' => '2026-08-13 09:25:43'],

        // 08-14 — eleven tier-1 analyses, of which one delivered.
        ['uuid' => '019fffbb-76e1-717f-8912-dd38c6e358b4', 'stage' => AuditAiStage::ANALYSIS, 'in' => 24511, 'out' => 1909, 'at' => '2026-08-14 16:15:02'],
        ['uuid' => '019fffbb-76e1-717f-8912-dd38c6e358b4', 'stage' => AuditAiStage::ANALYSIS, 'in' => 24511, 'out' => 3213, 'at' => '2026-08-14 16:17:19'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133557, 'out' => 3007, 'at' => '2026-08-14 16:20:59'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133557, 'out' => 3525, 'at' => '2026-08-14 16:24:07'],
        ['uuid' => '01a00115-8ca8-70e6-b992-038ed1e033df', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133557, 'out' => 3815, 'at' => '2026-08-14 16:26:19'],
        ['uuid' => '019fffbb-76e1-717f-8912-dd38c6e358b4', 'stage' => AuditAiStage::ANALYSIS, 'in' => 24511, 'out' => 2476, 'at' => '2026-08-14 16:27:59'],
        ['uuid' => '01a00115-8ca8-70e6-b992-038ed1e033df', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133557, 'out' => 2977, 'at' => '2026-08-14 16:29:42'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133557, 'out' => 3107, 'at' => '2026-08-14 16:31:58'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133543, 'out' => 2830, 'at' => '2026-08-14 16:42:27'],

        // The expensive pair: one logical deep review, billed twice. The SDK
        // retried after the 60s idle timeout and both generations completed
        // server-side, so both were charged for output nobody received. The
        // console records both inside the same minute; their order within it
        // is not recoverable, so they share a timestamp.
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::DEEP_REVIEW, 'in' => 180255, 'out' => 7397, 'at' => '2026-08-14 16:44:29'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::DEEP_REVIEW, 'in' => 180255, 'out' => 4065, 'at' => '2026-08-14 16:44:29'],

        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133543, 'out' => 3295, 'at' => '2026-08-14 16:57:06'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::DEEP_REVIEW, 'in' => 180401, 'out' => 8231, 'at' => '2026-08-14 16:59:01'],
        ['uuid' => '01a00115-8ca8-70e6-b992-038ed1e033df', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133701, 'out' => 3314, 'at' => '2026-08-14 20:26:41'],

        // 08-15 — the retries that finally delivered, after both bugs were fixed.
        ['uuid' => '01a00685-6376-73e8-897d-aa786eb07bbf', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133701, 'out' => 3476, 'at' => '2026-08-15 17:44:58'],
        ['uuid' => '01a00685-6376-73e8-897d-aa786eb07bbf', 'stage' => AuditAiStage::DEEP_REVIEW, 'in' => 178399, 'out' => 7957, 'at' => '2026-08-15 17:46:48'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::ANALYSIS, 'in' => 133701, 'out' => 3171, 'at' => '2026-08-15 17:47:52'],
        ['uuid' => '01a00111-d167-7350-b077-1ec90f77b25d', 'stage' => AuditAiStage::DEEP_REVIEW, 'in' => 178399, 'out' => 9198, 'at' => '2026-08-15 17:50:01'],
        ['uuid' => '019fffbb-76e1-717f-8912-dd38c6e358b4', 'stage' => AuditAiStage::ANALYSIS, 'in' => 24511, 'out' => 2536, 'at' => '2026-08-15 17:50:54'],
    ];

    /** Console rows that belong to no request, kept only to report the residue. */
    private const UNATTRIBUTED = [
        ['in' => 628, 'out' => 204], ['in' => 622, 'out' => 176],
        ['in' => 49, 'out' => 4282], ['in' => 49, 'out' => 16000],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $written = 0;
        $skipped = 0;
        $missing = [];
        $spend = 0.0;

        foreach (self::CALLS as $call) {
            $request = AuditRequest::query()->where('uuid', $call['uuid'])->first();

            if ($request === null) {
                $missing[$call['uuid']] = true;
                $skipped++;

                continue;
            }

            $at = Carbon::parse($call['at'], 'UTC');

            // Re-runnable by construction: the same call cannot be written
            // twice, and a pipeline-observed row is never displaced by a
            // transcribed one.
            $exists = AuditAiCall::query()
                ->where('audit_request_id', $request->id)
                ->where('stage', $call['stage']->value)
                ->where('input_tokens', $call['in'])
                ->where('output_tokens', $call['out'])
                ->where('created_at', $at)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $spend += $this->cost($call['in'], $call['out']);
            $written++;

            if ($dryRun) {
                continue;
            }

            $row = new AuditAiCall([
                'audit_request_id' => $request->id,
                'stage' => $call['stage']->value,
                'model' => self::MODEL,
                'outcome' => AuditAiCall::OUTCOME_OK,
                'source' => AuditAiCall::SOURCE_BACKFILL,
                'input_tokens' => $call['in'],
                'output_tokens' => $call['out'],
                // Unknown: the console reports tokens, not wall time.
                'duration_ms' => null,
            ]);

            // Set outside the fillable array on purpose. Timestamps are not
            // mass-assignable, so passing them to create() drops them silently
            // and stamps every historical call with now() — which both dates
            // the spend wrongly and defeats the exists() check above, making
            // the command duplicate its own output on a second run.
            $row->created_at = $at;
            $row->updated_at = $at;
            $row->save();
        }

        $this->report($dryRun, $written, $skipped, array_keys($missing), $spend);

        return self::SUCCESS;
    }

    /** @param list<string> $missing */
    private function report(bool $dryRun, int $written, int $skipped, array $missing, float $spend): void
    {
        $verb = $dryRun ? 'Would write' : 'Wrote';
        $this->info(sprintf(
            '%s %d call(s) worth $%s; skipped %d already present or unmatched.',
            $verb, $written, number_format($spend, 2), $skipped,
        ));

        foreach ($missing as $uuid) {
            $this->warn("No audit request matches {$uuid} — skipped. This backfill targets one database.");
        }

        $residue = 0.0;

        foreach (self::UNATTRIBUTED as $probe) {
            $residue += $this->cost($probe['in'], $probe['out']);
        }

        // Stated every run, not just the first: a reconciliation that omits its
        // own known residue is how a ledger starts lying by omission.
        $this->line(sprintf(
            'Not backfilled: $%s across %d manual probe(s) that belong to no audit request, '
                .'and 3 rejected deep reviews that were never billed.',
            number_format($residue, 2),
            count(self::UNATTRIBUTED),
        ));
    }

    private function cost(int $in, int $out): float
    {
        $rates = config('audit.model_pricing.'.self::MODEL);

        if (! is_array($rates)) {
            return 0.0;
        }

        return ($in * (float) $rates['input'] + $out * (float) $rates['output']) / 1_000_000;
    }
}
