@props(['route' => '#'])

@php($selected = request()->routeIs($route))
@php($selectedClass = $selected ? 'text-cream-100' : 'text-cream-200/60')

<li {{ $attributes }}>
    <a href="{{ str_starts_with($route, '#') ? (route('home') . $route) : route($route) }}" class="font-mono text-xs tracking-[0.12em] uppercase block py-2 px-3 md:p-0 rounded hover:text-cream-100 transition-colors {{ $selectedClass }}">
        {{ $slot }}
    </a>
</li>
