<?php

namespace App\View\Components\Plans;

use App\Models\Plan;
use App\Services\PartnerPricingResolver;
use App\Services\PlanService;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class All extends Component
{
    public function __construct(
        protected PlanService $planService,
        protected SubscriptionService $subscriptionService,
        protected PartnerPricingResolver $partnerPricingResolver,
        public array $products = [],
        public bool $isGrouped = true,
        public string $preselectedInterval = '',
        public bool $calculateSavingRates = false,
        public ?string $currentSubscriptionUuid = null,
        public bool $showDefaultProduct = false,
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.plans.all', $this->calculateViewData());
    }

    protected function calculateViewData()
    {
        $plans = $this->planService->getAllPlansWithPrices(
            $this->products,
            onlyVisible: true,
        );

        $plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());
        $plans = $this->partnerPricingResolver->purchasablePlans($plans, auth()->user());

        $viewData = [];
        $user = auth()->user();

        if ($user !== null && $this->subscriptionService->hasNonSelfServiceActiveSubscription($user)) {
            $viewData['warnBeforePlanPurchase'] = true;
        }

        return $this->enrichViewData($viewData, $plans);
    }

    protected function enrichViewData(array $viewData, Collection $plans)
    {
        $viewData['plans'] = $plans;

        if ($this->showDefaultProduct) {
            $defaultProduct = $this->planService->getDefaultProduct();

            if ($defaultProduct) {
                $viewData['defaultProduct'] = $defaultProduct;
            }
        }

        $viewData['isGrouped'] = $this->isGrouped;

        $groupedPlans = [];
        if ($viewData['isGrouped']) {
            $groupedPlans = $this->groupPlans($plans);
        }

        if ($this->calculateSavingRates) {
            $viewData['intervalSavingPercentage'] = $this->calculateSavingRates($groupedPlans);
        }

        $viewData['groupedPlans'] = $groupedPlans;
        $viewData['tierSections'] = array_map(fn (array $intervalPlans): array => $this->tierSections($intervalPlans), $groupedPlans);

        if (! empty($this->preselectedInterval) && ! array_key_exists($this->preselectedInterval, $groupedPlans)) {
            $this->preselectedInterval = '';
        }

        $viewData['preselectedInterval'] = empty($this->preselectedInterval)
            ? array_key_first($groupedPlans) ?? ''
            : $this->preselectedInterval;

        return $viewData;
    }

    /**
     * Split one interval's cards into tier sections (spec §6.3): packages
     * carry product.metadata.audit_tier_group; anything without it lands in
     * one trailing unlabelled section, so plans outside the package catalog
     * keep rendering exactly as before.
     *
     * @param  list<Plan>  $plans
     * @return list<array{key: ?string, title: ?string, headline: ?string, plans: list<Plan>}>
     */
    protected function tierSections(array $plans): array
    {
        $tiers = (array) config('pricing.package_tiers', []);
        $byTier = [];
        $ungrouped = [];

        foreach ($plans as $plan) {
            $key = data_get($plan->product?->metadata, 'audit_tier_group');

            if (is_string($key) && array_key_exists($key, $tiers)) {
                $byTier[$key][] = $plan;
            } else {
                $ungrouped[] = $plan;
            }
        }

        $sections = [];

        foreach ($tiers as $key => $tier) {
            if (isset($byTier[$key])) {
                $sections[] = ['key' => $key, 'title' => $tier['name'], 'headline' => $tier['headline'], 'plans' => $byTier[$key]];
            }
        }

        if ($ungrouped !== []) {
            $sections[] = ['key' => null, 'title' => null, 'headline' => null, 'plans' => $ungrouped];
        }

        return $sections;
    }

    private function groupPlans(Collection $plans): array
    {
        $arrayPlans = $plans->all();
        // sort by price
        usort($arrayPlans, function ($a, $b) {
            return $a->prices->first()->price <=> $b->prices->first()->price;
        });

        // group plans by interval
        $groupedPlans = [];
        foreach ($arrayPlans as $plan) {
            $groupedPlans[$plan->interval->name][] = $plan;
        }

        return $groupedPlans;
    }

    protected function calculateSavingRates(array $groupedPlans)
    {
        // calculate average savings for each interval
        // sum up all prices for each interval, then calculate the average savings
        $LowestPriceForInterval = [];
        $intervalSavingPercentage = [];
        foreach ($groupedPlans as $interval => $plans) {
            $LowestPriceForInterval[$interval] = $plans[0]->prices->first()->price;
        }

        // sort by price
        uksort($LowestPriceForInterval, function ($a, $b) {
            return $a <=> $b;
        });

        // calculate imaginary prices for each interval compared to the lowest interval
        for ($i = 1; $i < count($LowestPriceForInterval); $i++) {
            $currentInterval = array_keys($LowestPriceForInterval)[$i];
            $firstInterval = array_keys($LowestPriceForInterval)[0];

            $currentPrice = $LowestPriceForInterval[$currentInterval];
            $firstPrice = $LowestPriceForInterval[$firstInterval];

            $imaginaryPrice = $this->calculatePriceForImaginaryInterval($firstPrice, $firstInterval, $currentInterval);

            $intervalSavingPercentage[$currentInterval] = $imaginaryPrice == 0 ? 0 : (($imaginaryPrice - $currentPrice) / ($imaginaryPrice)) * 100;
        }

        return array_map(function ($saving) {
            return ceil($saving);
        }, $intervalSavingPercentage);
    }

    protected function calculatePriceForImaginaryInterval(int $currentPrice, string $currentInterval, string $imaginaryInterval): int
    {
        // per week (approximate)
        $intervalConversion = [
            'day' => 1 / 7,
            'week' => 1,
            'month' => 4,
            'year' => 48,
        ];

        $currentIntervalInWeeks = $intervalConversion[$currentInterval];
        $imaginaryIntervalInWeeks = $intervalConversion[$imaginaryInterval];

        return intval(ceil($currentPrice * $imaginaryIntervalInWeeks / $currentIntervalInWeeks));
    }
}
