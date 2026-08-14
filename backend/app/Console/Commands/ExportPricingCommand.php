<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates the marketing site's pricing data from config/pricing.php.
 *
 * A15 requires every figure shown anywhere to match backend configuration
 * exactly. The frontend builds independently, so it reads a COMMITTED data
 * file rather than fetching from a running backend — and --check in CI catches
 * the drift that arrangement would otherwise permit (spec §8.5).
 */
class ExportPricingCommand extends Command
{
    protected $signature = 'app:export-pricing {--check : Exit non-zero if the committed file is stale}';

    protected $description = 'Export backend pricing configuration to the marketing site data file';

    public function handle(): int
    {
        $target = base_path('../frontend/src/data/pricing.json');
        $generated = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if ($this->option('check')) {
            $current = file_exists($target) ? (string) file_get_contents($target) : '';

            if ($current !== $generated) {
                $this->error('frontend/src/data/pricing.json is out of date. Run: php artisan app:export-pricing');

                return self::FAILURE;
            }

            $this->info('Pricing data is current.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $generated);
        $this->info('Wrote '.$target);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $tiers = [];

        foreach (config('pricing.tiers') as $slug => $tier) {
            $tiers[$slug] = [
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price_cents' => $tier['price'],
                'price_display' => $this->display($tier['price']),
                'features' => $tier['features'],
            ];
        }

        $subscriptions = [];

        foreach (config('pricing.subscriptions') as $slug => $subscription) {
            $subscriptions[$slug] = [
                'name' => $subscription['name'],
                'price_cents' => $subscription['price'],
                'price_display' => $this->display($subscription['price']),
                'analyses_per_month' => $subscription['audit_analyses_per_month'],
                'deep_ai_credits' => $subscription['audit_deep_ai_credits'],
                'expert_credits' => $subscription['audit_expert_credits'],
                'is_popular' => $subscription['is_popular'],
            ];
        }

        return [
            '_generated' => 'php artisan app:export-pricing — do not edit by hand',
            'currency' => config('pricing.currency'),
            'tiers' => $tiers,
            'subscriptions' => $subscriptions,
        ];
    }

    private function display(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }
}
