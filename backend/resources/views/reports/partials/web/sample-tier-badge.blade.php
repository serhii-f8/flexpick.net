{{--
    Sample-report-only marker showing the lowest tier that includes a section.
    Tiers are cumulative, so a section tagged "Deep AI Code Review" is also in
    the Expert Audit. The label comes from AuditTier, never a literal. No price:
    a partner's visitors pay the partner's price, so the public sample must not
    quote the base catalog.
--}}
@php($badgeTier = \App\Constants\AuditTier::from($tier))
@php($badgeClass = match ($badgeTier) {
    \App\Constants\AuditTier::DEEP_AI => 'bg-sky-50 text-sky-900 ring-sky-200',
    \App\Constants\AuditTier::EXPERT => 'bg-green-50 text-green-900 ring-green-200',
    default => 'bg-stone-100 text-stone-700 ring-stone-200',
})
<p class="mb-2">
    <span class="inline-block rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider ring-1 {{ $badgeClass }}">
        {{ __('Included from') }} · {{ $badgeTier->label() }}
    </span>
</p>
