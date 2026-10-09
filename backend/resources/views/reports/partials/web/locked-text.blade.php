{{-- Stand-in for text that unlocks with the report: blurred filler, never the real words. --}}
<div class="relative mt-2">
    <div class="blur-[5px] select-none pointer-events-none text-sm text-stone-700" aria-hidden="true">
        @for ($i = 0; $i < ($lines ?? 2); $i++)
            <div class="mt-1">{{ str_repeat('█▌ ', 14) }}</div>
        @endfor
    </div>
    <div class="absolute inset-0 flex items-center justify-center"><span class="rounded-full bg-stone-900 px-4 py-1.5 text-xs text-stone-50">🔒 {{ __('Unlock to read') }}</span></div>
</div>
