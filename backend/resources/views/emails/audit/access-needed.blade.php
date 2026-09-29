<x-layouts.email>
    <x-slot name="preview">
        {{ $auditRequest->repo_url ? __("We couldn't reach your repository") : __('One more step for your codebase audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            @if ($auditRequest->repo_url && $accessProblem && $reconnect)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We couldn't access :url with your connected :provider account — its access may have been revoked or expired, or it can no longer read this repository.", ['url' => $auditRequest->repo_url, 'provider' => $providerLabel]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("This audit request is now closed and won't restart on its own. You haven't been charged for it.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('1. Reconnect your :provider account.', ['provider' => $providerLabel]) }}</strong>
                    {{ __('On the Git Connections page, reconnect the :provider account that can read this repository.', ['provider' => $providerLabel]) }}
                    <a href="{{ $gitConnectionsUrl }}" style="color: #2563eb;">{{ __('Open Git Connections') }}</a>
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('2. Then run a new audit.') }}</strong>
                    {{ __('Start it from your dashboard — the repository is already filled in. Reconnecting takes effect immediately, so there is no waiting.') }}
                </p>
                <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                    <a href="{{ $rerunUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                        {{ __('Run the audit again') }}
                    </a>
                </p>
                <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                    {{ __('Need help? Just reply to this email.') }}
                </p>
            @elseif ($auditRequest->repo_url && $accessProblem)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We couldn't access :url — it looks private (or the link isn't a reachable git repository).", ['url' => $auditRequest->repo_url]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("This audit request is now closed and won't restart on its own. You haven't been charged for it.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('1. Connect your :provider account.', ['provider' => $providerLabel]) }}</strong>
                    {{ __('From your workspace, connect the :provider account that can read this repository — private repos need this before we can analyze them.', ['provider' => $providerLabel]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('2. Then run a new audit.') }}</strong>
                    {{ __('Start it from your dashboard — the repository is already filled in. Connecting your account takes effect immediately, so there is no waiting.') }}
                </p>
                <p style="margin: 24px 0 0; line-height: 24px; text-align: center;">
                    <a href="{{ $rerunUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none;">
                        {{ __('Run the audit again') }}
                    </a>
                </p>
                <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                    {{ __('On another git host, or need help another way? Just reply to this email.') }}
                </p>
            @elseif ($auditRequest->repo_url)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We reached :url but couldn't analyze it:", ['url' => $auditRequest->repo_url]) }}
                    {{ $auditRequest->failure_reason }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("This audit request is now closed and won't restart on its own. You haven't been charged for it.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("Reply to this email and we'll help you get it analyzed.") }}
                </p>
            @else
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("You didn't include a repository link, so we couldn't start the automated analysis.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Reply to this email with a repository URL — for a private repo, connect the matching GitHub, GitLab, or Bitbucket account from your workspace first.') }}
                </p>
            @endif
        </td>
    </tr>
</x-layouts.email>
