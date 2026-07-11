<x-layouts.email>
    <x-slot name="preview">
        {{ __('One more step for your codebase audit') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <p style="margin: 0; line-height: 24px">
                {{ __('Hi :name,', ['name' => $auditRequest->name]) }}
            </p>
            @if ($auditRequest->repo_url)
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("We couldn't access :url — it looks private (or the link isn't a reachable git repository).", ['url' => $auditRequest->repo_url]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('To let us analyze it, invite our review account :account as a read-only collaborator on GitHub:', ['account' => config('audit.github_account')]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Repository → Settings → Collaborators → Add people → search for ":account" → set role to "Read".', ['account' => config('audit.github_account')]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('We\'ll start the analysis as soon as the invite is accepted — usually within one business day. On another git host, or prefer not to invite us? Just reply to this email. Happy to sign an NDA first.') }}
                </p>
            @else
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __("You didn't include a repository link, so we couldn't start the automated analysis.") }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Reply to this email with a repository URL — for private GitHub repos, also invite our review account :account as a read-only collaborator. Happy to sign an NDA first.', ['account' => config('audit.github_account')]) }}
                </p>
            @endif
        </td>
    </tr>
</x-layouts.email>
