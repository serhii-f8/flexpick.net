<x-filament-panels::page>
    @if ($canRun)
        @php
            $selected = collect($quotas)->firstWhere(fn ($q) => $q->tier->value === $tier);
            $launchBranches = $repoUrl ? ($branchesByRepo[rtrim($repoUrl, '/')] ?? null) : null;
        @endphp

        <x-filament::section class="fp-launch">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <div class="min-w-0">
                    <label class="fp-mono-label" for="audit-repo-url">{{ __('Repository URL') }}</label>
                    <x-filament::input.wrapper class="fp-launch-url mt-2">
                        <x-filament::input id="audit-repo-url" type="url" wire:model.live.blur="repoUrl" placeholder="https://github.com/you/repo" aria-describedby="audit-private-repo" autocomplete="off" spellcheck="false" />
                    </x-filament::input.wrapper>

                    <details id="audit-private-repo" class="fp-disclosure mt-2">
                        <summary>{{ __('Private repository?') }}</summary>
                        <p>
                            {{ __('Invite :account on GitHub as a read-only collaborator (Settings → Collaborators → Add people), then paste the URL here. We start the audit as soon as the invite lands.', ['account' => config('audit.github_account')]) }}
                        </p>
                    </details>
                </div>

                @if ($launchBranches !== null)
                    <div class="min-w-48">
                        <label class="fp-mono-label" for="audit-branch">{{ __('Branch') }}</label>
                        @if ($launchBranches !== [])
                            <x-filament::input.wrapper class="mt-2">
                                <x-filament::input.select id="audit-branch" wire:model="branch">
                                    <option value="">{{ __('Repo default branch') }}</option>
                                    @foreach ($launchBranches as $b)
                                        <option value="{{ $b }}">{{ $b }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        @else
                            <x-filament::input.wrapper class="mt-2">
                                <x-filament::input id="audit-branch" type="text" wire:model="branch" placeholder="{{ __('branch name (optional)') }}" />
                            </x-filament::input.wrapper>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('We couldn\'t look up branches for this repo — you can still type one, or leave blank for the default branch.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <fieldset class="mt-6">
                <legend class="fp-mono-label">{{ __('Audit type') }}</legend>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    @foreach ($quotas as $quota)
                        <label class="fp-tier-card">
                            <input type="radio" name="tier" wire:key="tier-{{ $quota->tier->value }}" wire:model.live="tier" value="{{ $quota->tier->value }}" class="sr-only" />
                            <span class="fp-tier-card-head">
                                <span class="fp-tier-card-name">{{ $quota->tier->label() }}</span>
                                @if ($quota->priceCents !== null)
                                    <span class="fp-tier-card-price">${{ number_format($quota->priceCents / 100) }}</span>
                                @endif
                            </span>
                            <span class="fp-tier-card-tagline">{{ $quota->tier->tagline() }}</span>
                            <span class="fp-tier-card-quota {{ $quota->hasRuns() ? '' : 'fp-tier-card-quota-empty' }}">
                                @if ($quota->hasRuns())
                                    @if ($quota->isLifetime)
                                        {{ trans_choice('{1} :count free run left|[2,*] :count free runs left', $quota->remaining(), ['count' => $quota->remaining()]) }}
                                    @else
                                        {{ __(':remaining of :limit left this month', ['remaining' => $quota->remaining(), 'limit' => $quota->limit]) }}
                                    @endif
                                @elseif ($quota->purchasable())
                                    {{ __('No credits left · buy for $:price', ['price' => number_format($quota->priceCents / 100)]) }}
                                @else
                                    {{ __('None left') }}
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="launchAudit" icon="heroicon-o-play" size="lg">
                    @if ($selected && ! $selected->hasRuns() && $selected->purchasable())
                        {{ __('Buy :tier for $:price', ['tier' => $selected->tier->label(), 'price' => number_format($selected->priceCents / 100)]) }}
                    @elseif ($selected)
                        {{ __('Run :tier', ['tier' => $selected->tier->label()]) }}
                    @else
                        {{ __('Run new audit') }}
                    @endif
                </x-filament::button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Most reports land in your inbox within the hour.') }}
                </span>
            </div>
        </x-filament::section>
    @endif

    <div class="mt-2 flex items-baseline justify-between gap-4">
        <h2 class="font-heading text-lg font-bold tracking-tight text-gray-950 dark:text-white">{{ __('Your repositories') }}</h2>
        @if ($repoGroups->isNotEmpty())
            <x-fp.label>{{ trans_choice('{1} :count repository|[2,*] :count repositories', $repoGroups->count(), ['count' => $repoGroups->count()]) }}</x-fp.label>
        @endif
    </div>

    @forelse ($repoGroups as $repoUrl => $group)
        @php
            $current = $group['scores']->last();
            $delta = $deltas[rtrim($repoUrl, '/')] ?? null;
            $latestReport = $group['reports']->first();
            $held = $latestReport->auditRequest->isHeldForExpertReview();
            $diagnostic = collect($quotas)->firstWhere(fn ($q) => $q->tier === \App\Constants\AuditTier::DIAGNOSTIC);
            $originTier = $latestReport->auditRequest->tier->value;
            $schedule = $schedules[rtrim($repoUrl, '/')] ?? null;
            $scheduleFrequency = $schedule->frequency ?? 'off';
            $scheduleTier = $schedule?->tier->value ?? \App\Constants\AuditTier::DIAGNOSTIC->value;
            $canSchedule = $diagnostic && $diagnostic->limit > 0;
        @endphp

        <div class="fp-panel fp-repo-panel">
            <div class="fp-panel-bar">
                <div class="min-w-0 flex-1">
                    <p class="fp-repo font-medium text-gray-950 dark:text-white">{{ \App\Support\RepoName::short($repoUrl) }}</p>
                    <a href="{{ $repoUrl }}" target="_blank" rel="noopener" class="fp-mono-label block truncate hover:text-primary-600 dark:hover:text-primary-400">{{ $repoUrl }}</a>
                </div>
                <span class="hidden text-xs text-gray-500 sm:block dark:text-gray-400">
                    {{ trans_choice('{1} :count audit|[2,*] :count audits', $group['reports']->count(), ['count' => $group['reports']->count()]) }}
                    · {{ $latestReport->created_at->diffForHumans() }}
                </span>
                @if ($canSchedule)
                    {{--
                        Re-run uses this repo's own configured schedule branch, never the launch
                        form's current selection. @js(...) does not compile inside a Blade
                        component tag's attribute value (only inside plain HTML tags) -- it
                        leaks through as literal, uncompiled text, producing invalid JS in the
                        browser. {!! Js::from(...) !!} is the directive-free equivalent and does
                        compile correctly here.
                    --}}
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="launchAudit({!! \Illuminate\Support\Js::from($repoUrl) !!}, {!! \Illuminate\Support\Js::from($originTier) !!}, {!! \Illuminate\Support\Js::from($schedule?->branch) !!})">
                        {{ __('Re-run') }}
                    </x-filament::button>
                @endif
            </div>

            <div class="fp-panel-body">
                <div class="grid gap-6 md:grid-cols-[auto_minmax(0,1fr)] md:items-center">
                    <div class="flex flex-wrap items-end gap-x-4 gap-y-1">
                        @if ($held)
                            <x-filament::badge color="warning">{{ __('In expert review') }}</x-filament::badge>
                        @else
                            <x-fp.score :value="$current" size="lg" />
                            @if ($delta !== null && $delta !== 0)
                                <span class="fp-delta {{ $delta > 0 ? 'fp-trend-up' : 'fp-trend-down' }}">
                                    {{ $delta > 0 ? '▲' : '▼' }} {{ sprintf('%+d', $delta) }}
                                </span>
                            @endif
                        @endif
                    </div>
                    @if (count($group['chartPoints']) > 1)
                        <div class="max-w-md">
                            @include('filament.dashboard.partials.sparkline', ['points' => $group['chartPoints']])
                        </div>
                    @endif
                </div>

                <ul class="fp-report-list mt-5">
                    @foreach ($group['reports'] as $report)
                        @php($reportTier = $report->auditRequest->tier)
                        <li class="fp-report-row">
                            <span class="fp-report-row-meta">
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $report->created_at->format(config('app.datetime_format', 'd/m/Y H:i')) }}</span>
                                <x-filament::badge :color="$reportTier->badgeColor()">{{ $reportTier->label() }}</x-filament::badge>
                            </span>
                            <span class="fp-report-row-actions">
                                @if ($report->auditRequest->isHeldForExpertReview())
                                    <x-filament::badge color="warning">{{ __('In expert review') }}</x-filament::badge>
                                @else
                                    <x-fp.score :value="data_get($report->payload, 'scores.overall')" size="sm" class="w-10 justify-end" />
                                    <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', $report) }}">
                                        {{ __('PDF') }}
                                    </x-filament::button>
                                    <x-filament::button tag="a" size="xs" color="primary" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">
                                        {{ __('View') }}
                                    </x-filament::button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($canSchedule)
                    <details class="fp-disclosure fp-schedule mt-4" @open($scheduleFrequency !== 'off')>
                        <summary>
                            {{ __('Schedule re-audits') }}
                            @if ($scheduleFrequency !== 'off')
                                <span class="fp-schedule-state">{{ $scheduleFrequency === 'weekly' ? __('weekly') : __('monthly') }}</span>
                            @endif
                        </summary>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    wire:change="setSchedule({!! \Illuminate\Support\Js::from($repoUrl) !!}, $event.target.value, {!! \Illuminate\Support\Js::from($scheduleTier) !!})"
                                    aria-label="{{ __('Audit schedule for :repo', ['repo' => $repoUrl]) }}"
                                >
                                    @foreach (['off' => __('No schedule'), 'weekly' => __('Audit weekly'), 'monthly' => __('Audit monthly')] as $value => $optionLabel)
                                        <option value="{{ $value }}" @selected($scheduleFrequency === $value)>{{ $optionLabel }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>

                            @if ($scheduleFrequency === 'weekly')
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        wire:change="setScheduleDay({!! \Illuminate\Support\Js::from($repoUrl) !!}, $event.target.value)"
                                        aria-label="{{ __('Day of week for :repo', ['repo' => $repoUrl]) }}"
                                    >
                                        @foreach ([0 => __('Sun'), 1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat')] as $value => $dayLabel)
                                            <option value="{{ $value }}" @selected(($schedule->day_of_week ?? now()->dayOfWeek) === $value)>{{ $dayLabel }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @elseif ($scheduleFrequency === 'monthly')
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        wire:change="setScheduleMonthDay({!! \Illuminate\Support\Js::from($repoUrl) !!}, $event.target.value)"
                                        aria-label="{{ __('Day of month for :repo', ['repo' => $repoUrl]) }}"
                                    >
                                        @for ($day = 1; $day <= 31; $day++)
                                            <option value="{{ $day }}" @selected(($schedule->day_of_month ?? now()->day) === $day)>{{ $day }}</option>
                                        @endfor
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @endif

                            @if ($scheduleFrequency !== 'off')
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        wire:change="setSchedule({!! \Illuminate\Support\Js::from($repoUrl) !!}, {!! \Illuminate\Support\Js::from($scheduleFrequency) !!}, $event.target.value)"
                                        aria-label="{{ __('Scheduled audit type for :repo', ['repo' => $repoUrl]) }}"
                                    >
                                        @foreach ($quotas as $quota)
                                            @if (! $quota->isLifetime)
                                                <option value="{{ $quota->tier->value }}" @selected($scheduleTier === $quota->tier->value)>{{ $quota->tier->labelWithPrice() }}</option>
                                            @endif
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @endif

                            <div x-data x-init="$wire.loadBranches(@js($repoUrl))">
                                @php($scheduleBranches = $branchesByRepo[rtrim($repoUrl, '/')] ?? [])
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        wire:change="setScheduleBranch({!! \Illuminate\Support\Js::from($repoUrl) !!}, $event.target.value)"
                                        aria-label="{{ __('Branch to schedule for :repo', ['repo' => $repoUrl]) }}"
                                    >
                                        <option value="">{{ __('Repo default branch') }}</option>
                                        @foreach ($scheduleBranches as $b)
                                            <option value="{{ $b }}" @selected($schedule?->branch === $b)>{{ $b }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        @if ($scheduleFrequency !== 'off')
                            @include('filament.dashboard.pages.partials.audit-calendar', [
                                'calendarMonth' => $calendarMonthStart,
                                'calendarData' => $calendarByRepo[$repoUrl] ?? ['past' => collect(), 'upcoming' => []],
                            ])
                        @endif
                    </details>
                @endif
            </div>
        </div>
    @empty
        <div class="fp-panel">
            <div class="fp-panel-body py-10 text-center">
                <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="mx-auto h-8 w-8 text-gray-400" />
                <p class="mt-3 font-heading text-lg font-bold text-gray-950 dark:text-white">{{ __('No audit reports yet') }}</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter a repository URL above to run your first audit. Every repository you audit gets its own panel here, with its score history.') }}
                </p>
            </div>
        </div>
    @endforelse
</x-filament-panels::page>
