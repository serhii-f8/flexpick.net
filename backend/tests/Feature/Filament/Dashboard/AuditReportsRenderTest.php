<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsRenderTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_the_page_lists_every_tier(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('Diagnostic Report')
            ->assertSee('Deep AI Code Review')
            ->assertSee('Expert Audit');
    }

    public function test_a_report_held_for_expert_review_is_visible(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        // Let the factory build its own request, then bend that request into
        // the held state -- this does not assume the report factory's FK name.
        $report = AuditReport::factory()->create(['user_id' => $user->id]);
        $report->auditRequest->update([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/held',
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('In expert review')
            ->assertDontSee(route('reports.download', $report));
    }

    public function test_each_listed_report_shows_the_tier_it_ran_at(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        $report = AuditReport::factory()->create(['user_id' => $user->id]);
        $report->auditRequest->update([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/deep',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $html = Livewire::test(AuditReports::class)->assertOk()->html();

        // A bare assertSee would be vacuous here: the launch form's tier picker
        // above the list already renders all four labels. Anchor on the repo
        // heading and look only at what follows it, so this proves the LIST row
        // carries the tier.
        $listMarkup = substr($html, (int) strpos($html, 'github.com/acme/deep'));

        $this->assertStringContainsString(AuditTier::DEEP_AI->label(), $listMarkup);
        $this->assertStringNotContainsString(AuditTier::EXPERT->label(), $listMarkup);
    }

    /**
     * The sentinel account name matters: asserting the shipped default would
     * pass just as well against a hardcoded string, and the whole point of
     * this note is that it tracks config('audit.github_account') — the same
     * source the access-needed email and the awaiting_access status use.
     */
    public function test_the_launch_form_explains_private_repo_access(): void
    {
        config()->set('audit.github_account', 'sentinel-review-account');

        [$user, $tenant] = $this->userWithAllowance(diagnostic: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('read-only collaborator')
            ->assertSee('sentinel-review-account');
    }

    public function test_the_score_chart_renders_a_colored_point_per_report(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        foreach ([[60, 10], [75, 0]] as [$score, $daysAgo]) {
            $request = AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => 'https://github.com/acme/charted',
                'created_at' => now()->subDays($daysAgo),
            ]);
            AuditReport::factory()->create([
                'audit_request_id' => $request->id,
                'user_id' => $user->id,
                'payload' => ['scores' => ['overall' => $score]],
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('text-emerald-500', false)
            ->assertSee('60 → 75', false);
    }

    /**
     * A repo URL interpolated into a wire:/x- attribute lands in a JavaScript
     * context, not an HTML text one. Blade's {{ }} turns a quote into &#039;,
     * but the HTML parser decodes that back to a bare ' before Alpine and
     * Livewire ever evaluate the attribute as JS -- so the quote breaks out of
     * the string literal and the rest of the URL runs as code. This asserts on
     * the entity-decoded document precisely because that is what the browser
     * hands the evaluator.
     *
     * The payload passes the launch guard (str_starts_with($url, 'http')), so
     * a user really can store it on their own AuditRequest and self-XSS.
     */
    public function test_repo_urls_are_escaped_for_the_javascript_context_of_wire_attributes(): void
    {
        $hostile = "http://x'); alert(1); //";

        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        $request = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => $hostile,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
        ]);

        $html = Livewire::test(AuditReports::class)->assertOk()->html();

        // Only the attributes that are evaluated as JavaScript. The URL is
        // also rendered as ordinary HTML text elsewhere on the card, where
        // {{ }}'s escaping is exactly right and decoding it back is expected.
        preg_match_all(
            '/(?:wire:(?:click|change|input|submit|keydown)[\w.:-]*|x-init|x-on:[\w.:-]+)="([^"]*)"/i',
            $html,
            $matches,
        );

        $expressions = array_map(
            fn (string $attribute): string => html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5),
            $matches[1],
        );

        $handlersWithTheUrl = array_filter(
            $expressions,
            fn (string $expression): bool => str_contains($expression, 'alert(1)'),
        );

        // The URL still has to reach the handlers -- escaped, not dropped.
        $this->assertNotEmpty($handlersWithTheUrl, 'No JS-context handler carried the repo URL.');

        foreach ($handlersWithTheUrl as $expression) {
            $this->assertStringNotContainsString(
                "'); alert(1)",
                $expression,
                "Repo URL broke out of its string literal in: {$expression}",
            );
        }
    }

    /**
     * @js(...) does not compile inside a Blade component tag's attribute
     * value (only inside plain HTML tags) -- it silently leaks through as
     * literal, uncompiled text, producing invalid JS in the browser. The
     * escaping test above only checks handlers that already contain the
     * repo URL, so a directive that failed to compile at all (and so never
     * received the URL) slipped past it undetected. This asserts directly
     * that no rendered wire:-family or x- attribute still contains an
     * uncompiled @js( call, which is what actually broke the Re-run button
     * (wire:click="launchAudit(@js($repoUrl), ...)" inside
     * <x-filament::button>) before the fix.
     */
    public function test_no_wire_or_alpine_attribute_leaks_an_uncompiled_js_directive(): void
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        $request = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/re-run-target',
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
        ]);

        $html = Livewire::test(AuditReports::class)->assertOk()->html();

        preg_match_all(
            '/(?:wire:(?:click|change|input|submit|keydown)[\w.:-]*|x-init|x-on:[\w.:-]+)="([^"]*)"/i',
            $html,
            $matches,
        );

        foreach ($matches[1] as $expression) {
            $this->assertStringNotContainsString(
                '@js(',
                html_entity_decode($expression, ENT_QUOTES | ENT_HTML5),
                "Uncompiled @js(...) directive leaked into a JS-context attribute: {$expression}",
            );
        }

        $this->assertStringContainsString(
            "wire:click=\"launchAudit('https:\\/\\/github.com\\/acme\\/re-run-target',",
            $html,
            'The Re-run button did not receive the repo URL at all.',
        );
    }

    public function test_the_calendar_shows_a_completed_and_a_skipped_day_for_a_scheduled_repo(): void
    {
        // Fixtures are created BEFORE freezing time: a User row created
        // under a frozen past "now" persists with that backdated
        // created_at, which pollutes any later test in the same run that
        // does an unscoped global aggregate over all Users (FeatureTest
        // does not roll back between tests) -- MetricServiceTest is exactly
        // such a test. Freezing only around the calendar rendering below
        // keeps this test's own assertions deterministic without leaking a
        // backdated fixture into the rest of the suite.
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        // The calendar renders inside a repo's card, which only appears once
        // the repo has at least one report (matching the real UI: the
        // schedule controls, and thus the calendar, live on that card). A
        // schedule with no prior report for its repo would never surface a
        // card to hold the calendar, so give it one here.
        $request = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/calendared',
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
        ]);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/calendared',
            'frequency' => 'weekly',
            'tier' => AuditTier::DIAGNOSTIC->value,
            'day_of_week' => 1,
        ]);
        AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-08-03',
            'status' => 'completed',
        ]);
        AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-08-04',
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('audit-calendar-day-completed', false)
            ->assertSee('audit-calendar-day-skipped', false);

        Carbon::setTestNow();
    }

    public function test_calendar_month_navigation_moves_forward_and_back(): void
    {
        // Fixtures are created BEFORE freezing time -- see the comment on
        // the previous test for why.
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        Livewire::test(AuditReports::class)
            ->assertSet('calendarMonth', '2026-08')
            ->call('nextCalendarMonth')
            ->assertSet('calendarMonth', '2026-09')
            ->call('prevCalendarMonth')
            ->call('prevCalendarMonth')
            ->assertSet('calendarMonth', '2026-07');

        Carbon::setTestNow();
    }
}
