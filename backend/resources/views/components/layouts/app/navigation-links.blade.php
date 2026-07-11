<x-nav.item route="pricing">{{ __('Pricing') }}</x-nav.item>
@guest
    <x-nav.item route="login" class="md:hidden">{{ __('Login') }}</x-nav.item>
@endguest
