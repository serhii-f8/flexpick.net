@php
    $operator = config('legal.operator');
    $operatorName = $operator['name'] ?: config('app.name');
    $contactEmail = config('legal.contact_email');

    // Built here rather than with inline @if blocks: the details are optional
    // until the operator fills them in, and a Blade conditional inside a
    // comma-separated clause leaves a space before the comma when it renders.
    $operatorDetails = collect([
        $operator['address'] ? 'with a business address at '.$operator['address'] : null,
        $operator['nip'] ? 'NIP '.$operator['nip'] : null,
    ])->filter()->implode(', ');
@endphp

<x-layouts.simple>

    <x-slot name="title">
        {{ __('Terms of Service') }}
    </x-slot>

    <x-heading.h1 class="md:text-4xl! text-4xl! pt-4 pb-2">
        {{ __('Terms of Service') }}
    </x-heading.h1>

    <p class="text-sm text-cream-200/50 mb-8">
        Last updated: {{ \Carbon\Carbon::parse(config('legal.effective_date'))->format('F j, Y') }}
    </p>

    <p class="mb-6">
        These Terms are an agreement between you and {{ $operatorName }},
        {{ $operator['form'] }}{{ $operatorDetails ? ', '.$operatorDetails : '' }}
        (“{{ config('app.name') }}”, “we”, “us”), governing your use of {{ config('app.url') }} and
        {{ config('app.frontend_url') }} (the “Service”). By creating an account, submitting a repository, or paying us
        for anything, you accept these Terms. If you do not accept them, do not use the Service.
    </p>

    <p class="mb-6">
        How we handle personal data and your source code is set out in our
        <a class="text-primary-500" href="{{ route('privacy-policy') }}">Privacy Policy</a>, which forms part of these
        Terms.
    </p>

    <x-heading.h2 class="text-xl mb-2">1. What the Service does</x-heading.h2>

    <p class="mb-6">
        {{ config('app.name') }} analyses a source repository you submit and produces a codebase health report. The
        analysis combines static analysis tools with AI-assisted review. Tiers differ in the depth of review they
        include — the Expert tier adds review by a human engineer before the report is released. Reports are delivered
        by email and in your dashboard, and you can set up recurring audits of the same repository. Current tiers,
        prices and included allowances are on the pricing page, which forms part of these Terms.
    </p>

    <x-heading.h2 class="text-xl mb-2">2. Accounts</x-heading.h2>

    <p class="mb-6">
        You must be at least 18 years old to hold an account, and the information you give us must be accurate and kept
        current. You are responsible for your credentials and for everything that happens under your account; enable
        two-factor authentication and tell us immediately if you suspect unauthorised access. If you add people to a
        team, you are responsible for their use of the Service and for making sure they may see what the team can see.
        We may refuse, suspend or close an account that breaks these Terms.
    </p>

    <x-heading.h2 class="text-xl mb-2">3. Your repository</x-heading.h2>

    <p class="mb-6">
        You may only submit repositories you own or are otherwise entitled to share with us. You confirm that doing so
        breaches no licence, contract, confidentiality obligation or third-party right, and that the repository does
        not contain personal data you are not permitted to disclose to a processor. You keep all rights in your code.
        You grant us a limited, non-exclusive licence to clone, store temporarily, analyse and process it solely to
        produce your report and operate the Service.
    </p>

    <p class="mb-6">
        We delete the clone when the run finishes. We treat your code as confidential and will sign an NDA on request
        before you grant us access.
    </p>

    <x-heading.h2 class="text-xl mb-2">4. Acceptable use</x-heading.h2>

    <p class="mb-4">You agree not to:</p>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li>submit repositories you have no right to submit;</li>
        <li>use the Service to analyse a third party's code in order to attack it, or to build a competing analysis
            product;</li>
        <li>circumvent quotas, credits, payment or report gating, or share access credentials to avoid paying;</li>
        <li>attempt to disrupt, overload, reverse engineer or gain unauthorised access to the Service;</li>
        <li>resell reports or resell access to the Service, except under the partner programme in section 9.</li>
    </ul>

    <x-heading.h2 class="text-xl mb-2">5. What a report is — and is not</x-heading.h2>

    <p class="mb-6">
        A report is an <strong>assessment, not a guarantee</strong>. It is produced by automated static analysis and
        AI-assisted review, and it can miss real problems and flag things that are not problems. It is not a security
        certification, a penetration test, an audit in any regulatory sense, and not legal, compliance or financial
        advice. Scores and benchmarks are comparative indicators, not measurements of fitness for any purpose. You
        remain responsible for reviewing findings and for every decision you make on the basis of one. Expert-tier
        human review improves a report's judgement; it does not turn it into a warranty.
    </p>

    <x-heading.h2 class="text-xl mb-2">6. Plans, credits and payment</x-heading.h2>

    <p class="mb-6">
        Audits can be bought individually or drawn from a subscription allowance. Subscription allowances are metered
        per calendar month: they reset at the start of each month and unused runs <strong>do not carry over</strong>.
        Prices are shown at checkout in the stated currency and are exclusive of any taxes we are required to add,
        which are shown before you pay.
    </p>

    <p class="mb-6">
        Payments are processed by our payment providers; a valid payment method is required and you authorise us,
        through that provider, to charge the fees your plan or purchase incurs. If a charge fails, we may invoice you
        for the amount due and suspend paid features until it is settled. Subscriptions renew automatically for
        successive billing periods until cancelled. We may change prices, and will give you reasonable notice before a
        change affects a renewal — continuing after that means you accept the new price.
    </p>

    <p class="mb-6">
        Where we offer a free trial, we may change or withdraw the offer at any time; unless you cancel before it ends,
        the subscription continues as a paid one.
    </p>

    <x-heading.h2 class="text-xl mb-2">7. Cancellation and refunds</x-heading.h2>

    <p class="mb-6">
        You can cancel a subscription at any time from your dashboard. Cancellation stops future charges; your plan and
        its remaining allowance stay usable until the end of the period you have already paid for. We do not
        automatically refund part-used periods, but if something went wrong with a purchase, write to us — we would
        rather fix it than argue about it, and we consider refund requests case by case.
    </p>

    <x-heading.h2 class="text-xl mb-2">8. Consumers: right of withdrawal</x-heading.h2>

    <p class="mb-6">
        If you are a consumer in the EU, you normally have 14 days to withdraw from a distance contract without giving
        a reason. Because an audit is digital content delivered immediately, when you start an audit run you expressly
        ask us to begin performance during that period and acknowledge that you lose the right of withdrawal for that
        run once it has been delivered. Any part of a subscription not yet performed remains withdrawable, and we will
        refund it in proportion. To withdraw, email
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. Nothing in these Terms
        limits the statutory rights you have as a consumer under Polish or EU law.
    </p>

    <x-heading.h2 class="text-xl mb-2">9. Partners and resellers</x-heading.h2>

    <p class="mb-6">
        We may allow approved partners to resell the Service to their own customers. A partner sets its own prices and
        contracts with its customers in its own name; those prices and that contract are the partner's responsibility,
        not ours, and a partner may not make commitments about the Service on our behalf. Our contractual relationship
        is with whoever holds the account and pays us for it. A partner is responsible for its customers' compliance
        with these Terms, and must have the right to submit any repository it submits on a customer's behalf. We may
        end a partner arrangement at any time; doing so does not by itself cancel a paid period already purchased.
    </p>

    <x-heading.h2 class="text-xl mb-2">10. Availability and changes</x-heading.h2>

    <p class="mb-6">
        We aim to keep the Service available, but we do not promise uninterrupted operation: maintenance, third-party
        outages and failures happen. Delivery times we publish — including any target turnaround for expert review —
        are goals, not guarantees. We may change, add or remove features; if we materially reduce something you are
        paying for, you may cancel and we will refund the unused part of your paid period.
    </p>

    <x-heading.h2 class="text-xl mb-2">11. Intellectual property</x-heading.h2>

    <p class="mb-6">
        The Service, its software, scoring methodology, benchmarks and branding are ours and remain ours. On payment,
        you receive a perpetual, non-exclusive licence to use the report we deliver inside your organisation, including
        sharing it with your own advisers and investors. You may not publish a report as if it were an independent
        certification of your code, or present our name or marks as an endorsement, without our written permission.
        Aggregated, anonymised statistics derived from audits — which cannot identify you or your code — may be used to
        maintain our benchmarks.
    </p>

    <x-heading.h2 class="text-xl mb-2">12. Third-party services</x-heading.h2>

    <p class="mb-6">
        The Service depends on third parties — source hosts, an AI analysis provider, payment providers, email
        delivery — and may link to sites we do not control. We are not responsible for their content, terms or
        practices, and their outages may affect ours.
    </p>

    <x-heading.h2 class="text-xl mb-2">13. Suspension and termination</x-heading.h2>

    <p class="mb-6">
        We may suspend or terminate your access if you breach these Terms, if your use threatens the security or
        stability of the Service, or if we are legally required to. Where circumstances allow, we will warn you first
        and give you a chance to fix the problem. You may stop using the Service at any time and ask us to delete your
        account. Sections that by their nature should survive termination — payment obligations already incurred,
        intellectual property, disclaimers, liability limits and governing law — survive it.
    </p>

    <x-heading.h2 class="text-xl mb-2">14. Disclaimers</x-heading.h2>

    <p class="mb-6">
        Except as these Terms expressly state, the Service is provided “as is” and “as available”, without warranties
        of any kind, whether express or implied, including implied warranties of merchantability, fitness for a
        particular purpose and non-infringement. We do not warrant that the Service will be uninterrupted or
        error-free, that defects will be corrected, or that a report will identify every issue in your codebase.
    </p>

    <x-heading.h2 class="text-xl mb-2">15. Limitation of liability</x-heading.h2>

    <p class="mb-6">
        To the maximum extent permitted by law, we are not liable for indirect, incidental, special or consequential
        loss, or for lost profits, revenue, data, goodwill or business opportunity, arising from your use of or
        inability to use the Service or from reliance on a report. Our total liability for all claims relating to the
        Service is limited to the amount you paid us in the 12 months before the event giving rise to the claim.
    </p>

    <p class="mb-6">
        Nothing here excludes or limits liability that cannot be excluded or limited by law — including liability for
        death or personal injury caused by negligence, for intentional fault, and the mandatory statutory rights of
        consumers.
    </p>

    <x-heading.h2 class="text-xl mb-2">16. Indemnity</x-heading.h2>

    <p class="mb-6">
        If you are using the Service as a business, you will indemnify us against claims and costs arising from your
        breach of these Terms, and in particular from your submitting a repository you had no right to submit.
    </p>

    <x-heading.h2 class="text-xl mb-2">17. Changes to these Terms</x-heading.h2>

    <p class="mb-6">
        We may revise these Terms. For material changes we will give account holders at least 30 days' notice by email
        before they take effect, and you may cancel before then if you do not accept them. Continuing to use the
        Service after a revision takes effect means you accept it.
    </p>

    <x-heading.h2 class="text-xl mb-2">18. Governing law and disputes</x-heading.h2>

    <p class="mb-6">
        These Terms are governed by {{ config('legal.governing_law') }}. Disputes are subject to the courts having
        jurisdiction over our registered place of business, except that a consumer keeps the protection of the
        mandatory law of their country of residence and may bring a claim in their own courts. We would much rather
        settle a disagreement by email first: write to
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. Consumers in the EU may
        also use the European Commission's online dispute resolution platform.
    </p>

    <x-heading.h2 class="text-xl mb-2">19. Contact</x-heading.h2>

    <p class="mb-6">
        Questions about these Terms:
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

</x-layouts.simple>
