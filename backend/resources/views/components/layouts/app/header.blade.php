<nav class="sticky top-0 z-50 bg-ink/70 backdrop-blur-md border-b border-white/5">
    <livewire:announcement.view />
    <div class="navbar max-w-(--breakpoint-xl) items-center mx-auto px-4">
        <div class="navbar-start">
            <div class="dropdown">
                <div tabindex="0" role="button" class="btn btn-ghost lg:hidden me-1 text-cream-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16" /></svg>
                </div>
                <ul tabindex="0" class="menu menu-lg dropdown-content mt-3 z-1 p-2 border border-white/10 bg-base-200 rounded-box w-52">
                    <x-layouts.app.navigation-links></x-layouts.app.navigation-links>
                </ul>
            </div>
            <a href="{{ config('app.frontend_url') }}" class="flex justify-center items-center">
                <x-wordmark />
            </a>
        </div>
        <div class="navbar-center hidden lg:flex">
            <x-nav>
                <x-layouts.app.navigation-links></x-layouts.app.navigation-links>
            </x-nav>
        </div>
        <div class="navbar-end gap-4">
            @auth
                <x-layouts.app.user-menu></x-layouts.app.user-menu>
            @else
                <x-link class="hidden md:block font-mono text-xs tracking-[0.12em] uppercase text-cream-200/70 hover:text-cream-100" href="{{route('login')}}">{{ __('Login') }}</x-link>
                {{-- Pricing is behind the login wall, so a guest's next step is to register, not to bounce off /login. --}}
                <x-button-link.primary elementType="a" href="{{ route('register') }}">{{ __('Get started') }}</x-button-link.primary>
            @endauth
        </div>
    </div>
</nav>
