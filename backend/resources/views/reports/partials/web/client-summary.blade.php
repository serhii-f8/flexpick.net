{{-- The section for the person who paid for the audit, not their engineer:
     what is wrong, what it may cost them, what fixing it buys -- in plain words.
     The overview always reads; the findings unlock with the rest of the report. --}}
@php($clientSummary = $payload['client_summary'])
<h2 class="text-base font-bold mb-1">{{ __('In plain terms') }}</h2>
<p class="text-xs text-stone-500 mb-3">{{ __('A non-technical summary of what we found, what it may cause, and what you gain by fixing it.') }}</p>
<p>{{ $clientSummary['overview'] }}</p>
@foreach ($clientSummary['findings'] as $finding)
    <div class="border-t border-stone-200 py-3.5">
        @if ($unlocked)
            <div class="font-semibold">{{ $finding['what'] }}</div>
            <div class="mt-2 text-sm text-stone-700">
                <div><strong>{{ __('What it may cause') }}:</strong> {{ $finding['consequence'] }}</div>
                <div class="mt-1"><strong>{{ __('What you gain by fixing it') }}:</strong> {{ $finding['gain'] }}</div>
            </div>
        @else
            <div class="relative">
                <div class="blur-[5px] select-none pointer-events-none text-stone-700">
                    <div class="font-semibold">{{ str_repeat('█▌ ', 12) }}</div>
                    <div class="mt-2 text-sm">
                        <div><strong>{{ __('What it may cause') }}:</strong> {{ str_repeat('█▌ ', 14) }}</div>
                        <div class="mt-1"><strong>{{ __('What you gain by fixing it') }}:</strong> {{ str_repeat('█▌ ', 14) }}</div>
                    </div>
                </div>
                <div class="absolute inset-0 flex items-center justify-center"><span class="rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50">🔒 {{ __('Unlock to read') }}</span></div>
            </div>
        @endif
    </div>
@endforeach
