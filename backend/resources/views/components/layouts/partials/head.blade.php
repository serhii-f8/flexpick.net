@php($description = isset($description) ? $description : config('app.description'))
<meta name="description" content="{{ $description }}">

@php($canonical = isset($canonical) ? $canonical : url()->current())
<link rel="canonical" href="{{ $canonical }}">

<title>
    @isset($title)
        {{ $title }} | {{ config('app.name', 'SaaSykit') }}
    @else
        {{ config('app.name', 'SaaSykit') }}
    @endisset
</title>

<link rel="shortcut icon" type="image/x-icon" href="{{asset('images/favicon.ico')}}">

@include('components.layouts.partials.social-cards')

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<!-- Scripts -->
@vite(['resources/css/app.css'])

@stack('head')

@livewireStyles
