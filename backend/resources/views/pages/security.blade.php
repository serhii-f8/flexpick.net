@php
    $contactEmail = config('legal.contact_email');
    $ndaUrl = asset('legal/flexpick-mutual-nda.docx');
    $maxRepoMb = config('audit.max_repo_size_mb');
    $cloneDepth = config('audit.clone_depth');
@endphp

<x-layouts.simple>

    <x-slot name="title">
        {{ __('Security & Privacy') }}
    </x-slot>

    <x-heading.h1 class="md:text-4xl! text-4xl! pt-4 pb-2">
        {{ __('Security & Privacy') }}
    </x-heading.h1>

    <p class="text-sm text-cream-200/50 mb-8">
        {{ __('How we get to your code, where it lives while we analyse it, and when it is gone.') }}
    </p>

    <div class="rounded-lg border border-cream-200/10 bg-surface p-5 mb-10 text-sm leading-relaxed">
        <p class="mb-3 font-semibold text-cream-100">The short version</p>
        <ul class="list-disc pl-5 space-y-1.5 text-cream-200/80">
            <li>We only ever <strong>read</strong> your repository: we check it is reachable and clone it. We never push, open pull requests or change settings.</li>
            <li>The clone lives in a temporary directory on our server for the length of one run, and is deleted when the run ends — whether it succeeds or fails.</li>
            <li>We keep the results (scores, findings by file and line, the report), never the source code.</li>
            <li>Files that look like secrets are never sent to our AI provider, and a detected secret is reported without its value.</li>
            <li>We will sign an NDA before you give us access — <a class="text-primary-500" href="#nda">download our template</a> or send us yours.</li>
        </ul>
    </div>

    <x-heading.h2 class="text-xl mb-2" id="access">How we access your repository</x-heading.h2>

    <x-heading.h3 class="text-lg mb-2">Public repositories</x-heading.h3>

    <p class="mb-6">
        A public repository is cloned anonymously over HTTPS, exactly as anyone on the internet could. No account,
        token or permission is involved.
    </p>

    <x-heading.h3 class="text-lg mb-2">Private repositories</x-heading.h3>

    <p class="mb-4">
        For a private repository, a workspace owner connects a GitHub, GitLab or Bitbucket account from the
        <strong>Git connections</strong> page of the dashboard. That is a standard OAuth authorisation on the
        provider's own site: we never see your password. These are the permissions we ask for:
    </p>

    <div class="overflow-x-auto mb-4">
        <table class="w-full text-sm border border-cream-200/10">
            <thead>
                <tr class="text-left text-cream-100">
                    <th class="p-3 border-b border-cream-200/10">{{ __('Provider') }}</th>
                    <th class="p-3 border-b border-cream-200/10">{{ __('Scopes requested') }}</th>
                    <th class="p-3 border-b border-cream-200/10">{{ __('What they allow') }}</th>
                </tr>
            </thead>
            <tbody class="text-cream-200/80">
                <tr>
                    <td class="p-3 border-b border-cream-200/10">GitLab</td>
                    <td class="p-3 border-b border-cream-200/10 font-mono">read_repository, read_api</td>
                    <td class="p-3 border-b border-cream-200/10">Read-only. The token cannot change anything.</td>
                </tr>
                <tr>
                    <td class="p-3 border-b border-cream-200/10">Bitbucket</td>
                    <td class="p-3 border-b border-cream-200/10 font-mono">repository, account</td>
                    <td class="p-3 border-b border-cream-200/10">Read-only access to repositories, plus your account identity so we can list them for you.</td>
                </tr>
                <tr>
                    <td class="p-3">GitHub</td>
                    <td class="p-3 font-mono">repo</td>
                    <td class="p-3">
                        GitHub offers OAuth apps no read-only scope for private repositories, so the token it issues
                        could technically write. <strong>We never use it to write</strong> — see below.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mb-4">Whatever the token technically permits, our software uses it for three things only:</p>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li><strong>Listing your repositories</strong> in the dashboard's repository picker, so you can choose one.</li>
        <li><strong>Checking a repository is reachable</strong> (<span class="font-mono text-cream-100">git ls-remote</span>) before an audit starts, and — for scheduled audits — whether it has changed since the last run.</li>
        <li><strong>Cloning it</strong> (<span class="font-mono text-cream-100">git clone</span>) for the audit.</li>
    </ul>

    <p class="mb-6">
        We never push commits, create branches, open pull requests or issues, add webhooks or deploy keys, or change
        repository settings.
    </p>

    <x-heading.h3 class="text-lg mb-2">How the token is protected</x-heading.h3>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li>Access and refresh tokens are <strong>encrypted at rest</strong> in our database.</li>
        <li>A token is handed to git only through the process environment, as an authorisation header limited to the
            provider's host. It never appears in a command line, a repository URL, the clone's
            <span class="font-mono text-cream-100">.git/config</span>, or a log. Git credential helpers are switched off, so nothing is cached on disk.</li>
        <li>A token is only ever used for its own workspace's repositories, and only for the provider it was issued by.</li>
        <li>Error reports are scrubbed of tokens and credentials before they are stored, and repository URLs are stripped of any embedded credentials in failure messages.</li>
    </ul>

    <x-heading.h3 class="text-lg mb-2">Revoking access</x-heading.h3>

    <p class="mb-6">
        Disconnecting a provider on the <strong>Git connections</strong> page deletes our stored tokens immediately.
        To invalidate the token at the source as well, revoke the FlexPick application in your GitHub, GitLab or
        Bitbucket settings — you can do that at any time, and we would rather you did when you no longer need us.
    </p>

    <x-heading.h2 class="text-xl mb-2" id="storage">Where your code is stored, and when it is deleted</x-heading.h2>

    <ul class="list-disc pl-5 space-y-3 mb-6">
        <li>
            <strong>On our own server, in a temporary directory.</strong> Each audit run clones into its own isolated
            working directory on our application server. The clone is not uploaded to object storage, not written to
            the database, and not shared between audits.
        </li>
        <li>
            <strong>Only as much as we need.</strong> We take a shallow clone of a single branch — the latest
            {{ $cloneDepth }} commits, no tags. A repository whose working tree exceeds {{ $maxRepoMb }} MB is
            rejected and its clone deleted on the spot.
        </li>
        <li>
            <strong>Deleted at the end of every run.</strong> The working directory is removed when the analysis
            finishes, whether it succeeded or failed, so your code is on our disk for the minutes the run takes. If a
            run is interrupted and retried, any leftover directory is wiped before the retry clones again.
        </li>
        <li>
            <strong>Scheduled audits repeat the cycle.</strong> A recurring audit clones fresh each time, then deletes
            that clone too. Nothing is kept between runs.
        </li>
    </ul>

    <x-heading.h3 class="text-lg mb-2">What leaves our server during a run</x-heading.h3>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li><strong>Static analysis runs locally.</strong> Code statistics, secret scanning, duplication detection
            and security rules run on our server against the clone.</li>
        <li><strong>Package names and versions</strong> from your dependency manifests go to the public OSV.dev
            vulnerability database. No source code is sent.</li>
        <li><strong>A capped number of short code excerpts</strong> go to {{ config('legal.ai_provider') }} so the
            findings can be interpreted, under commercial API terms that do not permit training models on them. We
            do not train models on your code either.</li>
        <li><strong>Secrets are withheld.</strong> <span class="font-mono text-cream-100">.env</span> files, private
            keys, credential files, and any file our secret scanner flags are never included in what is sent. A
            secret appears in your report by file and rule — never by its value.</li>
    </ul>

    <x-heading.h3 class="text-lg mb-2">What we keep afterwards</x-heading.h3>

    <p class="mb-4">
        The results, not the code: computed metrics, findings (the rule, the file path and the line number), the
        report and its PDF. They stay in your workspace so you can compare a repository with its own history.
        Ask us at <a class="text-primary-500" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> and we
        delete them.
    </p>

    <p class="mb-6">
        On the Expert tier a FlexPick reviewer, bound by confidentiality, reads the generated report and the
        excerpts it is based on before it is published to you. We also compute anonymized, platform-wide statistics
        — for example, the share of audited codebases with exposed secrets — which we and our partners may quote.
        They are aggregate percentages only and never identify you, your repository or your code.
    </p>

    <x-heading.h2 class="text-xl mb-2" id="nda">Non-disclosure agreement</x-heading.h2>

    <p class="mb-4">
        We are happy to sign an NDA before you grant us access to anything. Our mutual NDA covers exactly the
        handling described on this page, including the sub-processors an audit uses, so signing it does not
        conflict with how the service works.
    </p>

    <ol class="list-decimal pl-5 space-y-2 mb-4">
        <li><a class="text-primary-500" href="{{ $ndaUrl }}" download>Download the FlexPick Mutual NDA (.docx)</a>.</li>
        <li>Fill in your company details and sign it.</li>
        <li>Email the signed copy to <a class="text-primary-500" href="mailto:{{ $contactEmail }}?subject=Signed%20NDA">{{ $contactEmail }}</a>.
            We countersign and send it back, normally within one business day.</li>
    </ol>

    <p class="mb-6">
        If your organisation has its own NDA form, send that instead and we will review it.
    </p>

    <x-heading.h2 class="text-xl mb-2">Platform security</x-heading.h2>

    <ul class="list-disc pl-5 space-y-2 mb-6">
        <li>All traffic is encrypted in transit (HTTPS).</li>
        <li>Passwords are stored hashed, and two-factor authentication is available on every account.</li>
        <li>Every audit, report and git connection belongs to a workspace and is visible only to that workspace's members — and to FlexPick staff when operating the service or supporting you.</li>
        <li>Card details go straight to our payment providers and never reach our servers.</li>
        <li>Our error tracker is self-hosted and strips tokens and credentials before storing anything.</li>
    </ul>

    <x-heading.h2 class="text-xl mb-2">Reporting a vulnerability</x-heading.h2>

    <p class="mb-6">
        Found a security issue in FlexPick? Email
        <a class="text-primary-500" href="mailto:{{ $contactEmail }}?subject=Security%20report">{{ $contactEmail }}</a>
        with the details. We reply within one business day and will keep you posted until it is fixed. Please give
        us a reasonable time to fix it before disclosing it publicly.
    </p>

    <p class="mb-6 text-sm text-cream-200/60">
        This page explains our practices in plain language. The legal terms are in our
        <a class="text-primary-500" href="{{ route('privacy-policy') }}">Privacy Policy</a> and
        <a class="text-primary-500" href="{{ route('terms-of-service') }}">Terms of Service</a>.
    </p>

</x-layouts.simple>
