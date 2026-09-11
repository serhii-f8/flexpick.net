<?php

namespace App\View\Components\Plans;

use App\Models\Product;
use App\Services\PlanService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The "Start for free" panel for the default product. Extracted from
 * `plans/all` so the pricing page can render it outside the catalog tabs
 * (it is neither a subscription card nor a one-time product) while
 * `<x-plans.all show-default-product>` keeps rendering it inline.
 */
class DefaultProduct extends Component
{
    public ?Product $product;

    public function __construct(PlanService $planService, ?Product $product = null)
    {
        $this->product = $product ?? $planService->getDefaultProduct();
    }

    public function render(): View|Closure|string
    {
        return view('components.plans.default-product');
    }
}
