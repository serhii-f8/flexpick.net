@props(['backButton' => true])

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.layouts.partials.head')
</head>
<body {{ $attributes->merge(['class' => 'bg-ink text-cream-200 font-sans w-full']) }} >
    <div id="app">

        <div class="flex justify-between">
            <a href="{{route('home')}}" class="inline-block m-6">
                <x-wordmark />
            </a>

            @if($backButton)
                <div class="self-end m-4">
                    <x-link href="{{route('home')}}" class="flex items-center font-mono text-[11px] tracking-[0.12em] uppercase text-cream-200/50 hover:text-cream-100">{{__('<< back')}}</x-link>
                </div>
            @endif
        </div>

        <div>
            {{$slot}}
        </div>

        @include('components.layouts.partials.tail', ['skipCookieContentBar' => true])
    </div>
</body>
</html>
