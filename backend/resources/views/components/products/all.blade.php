@inject('productService', 'App\Services\OneTimeProductService')

<div {{ $attributes->merge(['class' => 'fp-plan-grid']) }}>
    @foreach($products as $product)
        @php
            $price = $productService->getProductPrice($product);
            $features = collect($product->features ?? [])->pluck('feature')->filter()->values()->all();
        @endphp

        <x-fp.plan-card
            :name="$product->name"
            :description="$product->description"
            :price="money($product->partner_price ?? $price->price, $price->currency->code)"
            :interval="__('one-off')"
            :features="$features"
            :partner="$product->partner_price !== null ? $product->partner_tenant_name : null"
            :href="route('buy.product', ['productSlug' => $product->slug])"
            :cta="__('Get :name', ['name' => $product->name])"
        >
            @if (!empty($extraDescription))
                <p class="fp-plan-card-desc">{{ $extraDescription }}</p>
            @endif
        </x-fp.plan-card>
    @endforeach
</div>
