{{-- No Pricing item: this layout renders on public pages (terms, privacy, a
     shared audit report) and /pricing now requires authentication, so for a
     guest the link only ever led to the login wall. --}}
@guest
    <x-nav.item route="login" class="md:hidden">{{ __('Login') }}</x-nav.item>
@endguest
