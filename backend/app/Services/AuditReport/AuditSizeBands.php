<?php

namespace App\Services\AuditReport;

use App\Services\ConfigService;

/**
 * How many runs an audit costs, by repository size (lines of code from scc).
 *
 * Admin-overridable through `audit.size_bands` (Audit Settings). The
 * customer-facing copy comes from describe(), which reads the same bands,
 * so what we charge and what we say we charge cannot drift apart.
 */
class AuditSizeBands
{
    /** @var list<array{max_loc: int, runs: int}> */
    public const DEFAULT_BANDS = [
        ['max_loc' => 100000, 'runs' => 1],
        ['max_loc' => 300000, 'runs' => 2],
    ];

    public function __construct(private ConfigService $configService) {}

    /** @return list<array{max_loc: int, runs: int}> */
    public function bands(): array
    {
        // A bad stored value degrades to the defaults rather than failing a
        // paid run mid-pipeline; the settings form refuses such values before
        // they are saved (AuditSettings).
        return self::normalize($this->configService->get('audit.size_bands')) ?? self::DEFAULT_BANDS;
    }

    /** Null means above the top band: too large for self-serve. */
    public function runsFor(int $loc): ?int
    {
        foreach ($this->bands() as $band) {
            if ($loc <= $band['max_loc']) {
                return $band['runs'];
            }
        }

        return null;
    }

    public function ceiling(): int
    {
        $bands = $this->bands();

        return $bands[array_key_last($bands)]['max_loc'];
    }

    /** @return list<string> */
    public function describe(): array
    {
        $lines = [];
        $previous = null;

        foreach ($this->bands() as $band) {
            $runs = trans_choice('{1} :count run|[2,*] :count runs', $band['runs'], ['count' => $band['runs']]);

            $lines[] = $previous === null
                ? __('Up to :max lines of code: :runs', ['max' => number_format($band['max_loc']), 'runs' => $runs])
                : __(':min–:max lines of code: :runs', ['min' => number_format($previous + 1), 'max' => number_format($band['max_loc']), 'runs' => $runs]);

            $previous = $band['max_loc'];
        }

        $lines[] = __('Over :max lines of code: contact us for a scoped audit', ['max' => number_format((int) $previous)]);

        return $lines;
    }

    /**
     * Validated, ascending bands, or null if the value is not a usable band
     * list. Accepts the stored JSON string or an already-decoded array.
     *
     * @return list<array{max_loc: int, runs: int}>|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $bands = [];

        foreach ($raw as $band) {
            if (! is_array($band) || ! isset($band['max_loc'], $band['runs'])
                || ! is_numeric($band['max_loc']) || ! is_numeric($band['runs'])
                || (int) $band['max_loc'] < 1 || (int) $band['runs'] < 1) {
                return null;
            }

            $bands[] = ['max_loc' => (int) $band['max_loc'], 'runs' => (int) $band['runs']];
        }

        usort($bands, fn (array $a, array $b): int => $a['max_loc'] <=> $b['max_loc']);

        for ($i = 1; $i < count($bands); $i++) {
            // A bigger repo can never cost fewer runs, and two bands can't
            // share a boundary.
            if ($bands[$i]['max_loc'] === $bands[$i - 1]['max_loc'] || $bands[$i]['runs'] < $bands[$i - 1]['runs']) {
                return null;
            }
        }

        return $bands;
    }
}
