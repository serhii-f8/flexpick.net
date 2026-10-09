{{-- Switches between the two reports of one audit. $tabUrls holds a signed
     URL for each, so a reader never hits an expired or unsigned link. --}}
<nav class="mb-5 flex gap-1 rounded-xl border border-stone-200 bg-white p-1 text-sm font-semibold" aria-label="{{ __('Report version') }}">
    @foreach (\App\Constants\ReportVariant::cases() as $tab)
        <a href="{{ $tabUrls[$tab->value] }}"
           @if ($tab === $variant) aria-current="page" @endif
           @class([
               'flex-1 rounded-lg px-4 py-2 text-center no-underline',
               'bg-stone-900 text-stone-50' => $tab === $variant,
               'text-stone-600 hover:bg-stone-100' => $tab !== $variant,
           ])>{{ $tab->tabLabel() }}</a>
    @endforeach
</nav>
