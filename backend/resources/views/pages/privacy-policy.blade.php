@php
    $operator = config('legal.operator');
    $operatorName = $operator['name'] ?: config('app.name');
    $contactEmail = config('legal.contact_email');
@endphp

<x-layouts.simple>

    <x-slot name="title">
        {{ __('Privacy Policy') }}
    </x-slot>

    <x-heading.h1 class="md:text-4xl! text-4xl! pt-4 pb-2">
        {{ __('Privacy Policy') }}
    </x-heading.h1>

    <p class="text-sm text-cream-200/50 mb-8">
        Last updated: {{ \Carbon\Carbon::parse(config('legal.effective_date'))->format('F j, Y') }}
    </p>

    <div class="rounded-lg border border-cream-200/10 bg-surface p-5 mb-10 text-sm leading-relaxed">
        <p class="mb-3 font-semibold text-cream-100">The short version</p>
        <ul class="list-disc pl-5 space-y-1.5 text-cream-200/80">
            <li>We clone your repository, analyse it, and delete the clone when the run finishes.</li>
            <li>Limited code excerpts are sent to our AI analysis provider. Files that look like secrets are never sent.</li>
            <li>We do not train models on your code, and we do not sell your data to anyone.</li>
            <li>Card details never reach our servers — our payment providers handle them.</li>
            <li>Write to <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> to get a copy of your data or have it deleted.</li>
        </ul>
    </div>

    <x-heading.h2 class="text-xl mb-2">Who we are</x-heading.h2>

    <p class="mb-6">
        {{ config('app.name') }} (“we”, “us”, “our”) is a code-audit service operated by
        {{ $operatorName }}, {{ $operator['form'] }}.
        @if ($operator['address'])
            Business address: {{ $operator['address'] }}.
        @endif
        @if ($operator['nip'])
            NIP: {{ $operator['nip'] }}.
        @endif
        @if ($operator['regon'])
            REGON: {{ $operator['regon'] }}.
        @endif
        We are the data controller for the personal data described in this policy. You can reach us at
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <p class="mb-6">
        This policy covers the application at {{ config('app.url') }} and the marketing site at
        {{ config('app.frontend_url') }} (together, the “Service”). Terms used here have the meaning given in our
        <a class="text-primary-500" href="{{ route('terms-of-service') }}">Terms of Service</a>.
    </p>

    <x-heading.h2 class="text-xl mb-2">What we collect</x-heading.h2>

    <p class="mb-4">
        We collect only what the Service needs in order to run an audit, bill for it, and keep the platform working.
    </p>

    <x-heading.h3 class="text-lg mb-2">Audit request data</x-heading.h3>

    <p class="mb-6">
        Your name, email address, the repository URL and optional branch you submit, and any message you add. We also
        record the IP address and browser user-agent of the submission, which we use for abuse prevention and rate
        limiting. You can request an audit before creating an account; the request is then linked to your account when
        you sign up with the same email address.
    </p>

    <x-heading.h3 class="text-lg mb-2">Your code</x-heading.h3>

    <p class="mb-6">
        To produce a report we make a temporary copy of the repository you submit. What happens to it is described in
        detail in <a class="text-primary-500" href="#code">How we handle your code</a> below.
    </p>

    <x-heading.h3 class="text-lg mb-2">Account and team data</x-heading.h3>

    <p class="mb-6">
        Your name, display name, email address, a hashed password, and — if you sign in with Google, GitHub or
        Facebook — the account identifier and email address that provider returns to us. If you use two-factor
        authentication we store the secret needed to verify your codes. If you create or join a team, we store team
        membership and the role you hold in it, which is visible to other members of that team.
    </p>

    <x-heading.h3 class="text-lg mb-2">Billing data</x-heading.h3>

    <p class="mb-6">
        Your plan, subscription status, orders, invoices, transactions, credits used, and the billing details you enter
        at checkout. <strong>We never see or store your card number.</strong> Card data goes directly to the payment
        provider handling the transaction; we receive only a reference, the amount, and the outcome.
    </p>

    <x-heading.h3 class="text-lg mb-2">Technical and usage data</x-heading.h3>

    <p class="mb-6">
        Server logs, error reports, queue and pipeline diagnostics, and records of emails we have sent you about your
        audits (recipient, subject, body and delivery status), which we keep so we can tell you what was sent and prove
        whether it was delivered.
    </p>

    <x-heading.h2 class="text-xl mb-2" id="code">How we handle your code</x-heading.h2>

    <p class="mb-4">
        This is the part of the Service that matters most, so here is exactly what happens when you submit a repository.
    </p>

    <ul class="list-disc pl-5 space-y-3 mb-6">
        <li>
            <strong>We clone it shallowly.</strong> We take a limited-depth clone of a single branch, without tags,
            into an isolated working directory on our own server. Repositories above a size limit are rejected rather
            than analysed.
        </li>
        <li>
            <strong>We delete the clone when the run ends.</strong> The working directory is removed whether the run
            succeeds or fails. We do not keep a copy of your repository.
        </li>
        <li>
            <strong>Most analysis happens on our server.</strong> Static analysis tools for code statistics, secret
            detection, duplication detection and security patterns run locally against the clone. Your code is not sent
            anywhere for these steps.
        </li>
        <li>
            <strong>Dependency names are checked against a public vulnerability database.</strong> We send the package
            names and version numbers from your dependency manifests to the OSV.dev vulnerability API. No source code
            is sent in this step.
        </li>
        <li>
            <strong>Limited excerpts are sent to our AI provider.</strong> To interpret the findings we send computed
            metrics, the detected issues, and a bounded set of file excerpts to {{ config('legal.ai_provider') }}. The
            amount is capped per tier. We send this under commercial API terms that do not permit our provider to train
            models on it, and we do not use your code to train models ourselves.
        </li>
        <li>
            <strong>Suspected secrets are withheld from the AI provider.</strong> The contents of files matching a
            secret denylist (<span class="font-mono text-cream-100">.env</span> files, private keys, credential files
            and similar) and of files flagged by our secret scanner are never included in what we send. A detected secret
            still appears in your report as a finding — telling you the file and rule, never the matched value.
        </li>
        <li>
            <strong>Private repositories.</strong> If you grant our GitHub machine account read access so we can audit
            a private repository, that access is used only to clone it for your audits. You can revoke it at any time
            from your GitHub settings.
        </li>
        <li>
            <strong>Expert-tier reports are read by a person.</strong> On the Expert tier a reviewer at
            {{ config('app.name') }} reads the generated report, and the excerpts it is based on, before it is
            published to you. Reviewers are bound by confidentiality.
        </li>
        <li>
            <strong>Scheduled audits re-clone on a schedule.</strong> If you set up a recurring audit, the steps above
            repeat at the interval you chose until you delete the schedule.
        </li>
    </ul>

    <p class="mb-6">
        What we keep after a run is the derived material, not the code itself: the computed metrics, the findings, the
        report payload and the generated PDF. We are happy to sign an NDA before you grant us access to anything.
    </p>

    <x-heading.h2 class="text-xl mb-2">Why we are allowed to use it</x-heading.h2>

    <p class="mb-4">
        Under the GDPR we rely on the following legal bases:
    </p>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li><strong>Performance of a contract</strong> — running audits, delivering reports, managing your account,
            teams and subscription, and sending transactional email about them.</li>
        <li><strong>Legitimate interests</strong> — preventing abuse of the audit pipeline, securing the platform,
            diagnosing failures, and improving the product in aggregate.</li>
        <li><strong>Consent</strong> — marketing email, and any non-essential cookies. You can withdraw consent at any
            time without affecting what we did before you withdrew it.</li>
        <li><strong>Legal obligation</strong> — keeping invoices and accounting records for the period Polish tax law
            requires.</li>
    </ul>

    <x-heading.h2 class="text-xl mb-2">Who we share it with</x-heading.h2>

    <p class="mb-4">
        Only the processors the Service actually needs. <strong>We do not sell your personal data, and we do not share
        it for advertising.</strong>
    </p>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li><strong>Hosting and infrastructure</strong> — the provider running our servers, database and object storage.</li>
        <li><strong>AI analysis</strong> — {{ config('legal.ai_provider') }}, as described above.</li>
        <li><strong>Vulnerability data</strong> — the OSV.dev API, for dependency names and versions only.</li>
        <li><strong>Source hosting</strong> — GitHub and any other host you point us at, when we clone your repository.</li>
        <li><strong>Email delivery</strong> — our transactional email provider.</li>
        <li><strong>Payments</strong> — the payment provider handling your transaction (Stripe, Paddle, Lemon Squeezy,
            Polar or Creem, depending on the checkout). Each is a PCI-DSS compliant processor with its own privacy
            policy.</li>
        <li><strong>Error tracking</strong> — our self-hosted error tracker, which automatically redacts tokens and
            credentials from anything it records.</li>
        <li><strong>Analytics</strong> — only if analytics is enabled on the site, and then only after you accept
            cookies.</li>
        <li><strong>Authorities</strong> — where we are legally required to disclose, or where disclosure is necessary
            to establish or defend a legal claim.</li>
        <li><strong>A successor</strong> — if the business is sold or merged, your data may transfer with it. We will
            tell you before that happens.</li>
    </ul>

    <p class="mb-6">
        Some of these providers are outside the European Economic Area. Where that is the case we rely on an adequacy
        decision or on the European Commission's Standard Contractual Clauses.
    </p>

    <x-heading.h2 class="text-xl mb-2">How long we keep it</x-heading.h2>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li><strong>Repository clones</strong> — deleted at the end of each run.</li>
        <li><strong>Unverified audit requests</strong> — deleted after 7 days if the email address is never confirmed.</li>
        <li><strong>Report links</strong> — signed links we email you expire after 30 days; you can always reopen the
            report from your dashboard.</li>
        <li><strong>Audit requests and reports</strong> — kept while your account is open, so you can compare a
            repository against its own history, and deleted on request.</li>
        <li><strong>Account data</strong> — kept while your account is open.</li>
        <li><strong>Invoices and accounting records</strong> — kept for as long as Polish tax law requires, even after
            you close your account.</li>
        <li><strong>Logs and error reports</strong> — kept for a short diagnostic window and then rotated out.</li>
    </ul>

    <x-heading.h2 class="text-xl mb-2">Cookies</x-heading.h2>

    <p class="mb-6">
        We use a session cookie to keep you logged in, security cookies to protect against cross-site request forgery,
        and preference cookies to remember settings such as your theme. These are strictly necessary and are set
        whether or not you accept anything. If analytics or marketing scripts are enabled on the site, a consent banner
        appears first and those scripts load only after you accept; declining leaves the Service fully usable. You can
        also block or delete cookies in your browser, though the Service will not work correctly without the necessary
        ones.
    </p>

    <x-heading.h2 class="text-xl mb-2">Your rights</x-heading.h2>

    <p class="mb-4">
        Wherever you are, you can ask us for a copy of your data or ask us to delete it. If you are in the EU or EEA,
        the GDPR gives you the right to:
    </p>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li>access the personal data we hold about you, and receive a copy of it;</li>
        <li>have inaccurate data corrected;</li>
        <li>have your data erased;</li>
        <li>restrict or object to our processing, including profiling based on legitimate interests;</li>
        <li>receive the data you gave us in a portable, machine-readable format;</li>
        <li>withdraw consent at any time, where we relied on consent.</li>
    </ul>

    <p class="mb-6">
        You can update most of your details yourself in your account settings. For anything else, email
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> — we act within 30 days.
        We may ask you to verify your identity first. If you think we have handled your data badly, please tell us so
        we can fix it; you also have the right to complain to {{ config('legal.supervisory_authority') }}, or to the
        supervisory authority of the country you live in.
    </p>

    <x-heading.h2 class="text-xl mb-2">Security</x-heading.h2>

    <p class="mb-6">
        Traffic is encrypted in transit. Passwords are stored hashed, never in plain text, and two-factor
        authentication is available on your account. Repository clones live in isolated working directories and are
        removed after each run. Our error tracker strips tokens and credentials before storing anything. No system is
        perfectly secure, and we cannot guarantee absolute security — but if we ever suffer a breach affecting your
        data, we will notify you and the supervisory authority as the GDPR requires.
    </p>

    <x-heading.h2 class="text-xl mb-2">Children</x-heading.h2>

    <p class="mb-6">
        The Service is for business use and is not directed at children. You must be at least 18 to hold an account. We
        do not knowingly collect data from children; if you believe a child has given us personal data, contact us and
        we will delete it.
    </p>

    <x-heading.h2 class="text-xl mb-2">Do Not Track</x-heading.h2>

    <p class="mb-6">
        We do not respond to “Do Not Track” browser signals, because there is no agreed standard for what they mean.
        Our cookie banner, where shown, is the control that governs non-essential tracking.
    </p>

    <x-heading.h2 class="text-xl mb-2">Changes to this policy</x-heading.h2>

    <p class="mb-6">
        We may update this policy. When we do, we change the date at the top of this page, and for material changes we
        also notify account holders by email before the change takes effect. Continuing to use the Service after that
        means you accept the updated policy.
    </p>

    <x-heading.h2 class="text-xl mb-2">Contact us</x-heading.h2>

    <p class="mb-6">
        Questions about this policy, or about the data we hold on you:
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

</x-layouts.simple>
