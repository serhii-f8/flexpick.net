<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.layouts.partials.head')
</head>
<body class="bg-ink text-cream-200 font-sans">
    <div id="app">

        <div class="w-full">
            <div class="flex flex-col-reverse flex-wrap md:flex-nowrap md:flex-row md:min-h-screen">
                 <div class="md:basis-3/5 flex flex-col">
                     <div class="hidden md:block p-6">
                         <a href="{{route('home')}}">
                            <x-wordmark />
                         </a>
                     </div>

                     {{$left}}
                 </div>
                <div class="md:basis-2/5 md:min-h-screen bg-white/2 border-s border-white/5 flex flex-col">
                    <div class="flex justify-between md:justify-end">
                        <div class="md:hidden p-6">
                            <a href="{{route('home')}}">
                                <x-wordmark />
                            </a>
                        </div>

                        <div class="self-end m-4">
                            <x-link href="{{route('home')}}" class="flex items-center font-mono text-[11px] tracking-[0.12em] uppercase text-cream-200/50 hover:text-cream-100">{{__('< back home')}}</x-link>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center gap-3 md:px-12 pt-20">
                        <span class="w-10 h-0.5 bg-primary-500"></span>
                        <span class="font-mono text-[11px] tracking-[0.14em] text-cream-200/50">{{ __('FLEXPICK') }}</span>
                    </div>

                    {{$right}}
                </div>
            </div>
        </div>

        @include('components.layouts.partials.tail')
    </div>
</body>
</html>
