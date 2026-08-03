<?php

namespace Database\Seeders\Demo;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Carbon\CarbonInterface;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AuditDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function __construct(
        private TenantPermissionService $tenantPermissionService,
    ) {}

    public function run(): void
    {
        $this->call(AuditMonetizationSeeder::class); // catalog must exist (idempotent)

        $starter = $this->subscribedUser('audit-starter-demo@flexpick.net', 'Audit Starter Demo', 'audit-starter-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $growth = $this->subscribedUser('audit-growth-demo@flexpick.net', 'Audit Growth Demo', 'audit-growth-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $scale = $this->subscribedUser('audit-agency-demo@flexpick.net', 'Audit Agency Demo', 'audit-agency-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $this->subscribedUser('audit-trial-demo@flexpick.net', 'Audit Trial Demo', 'audit-starter-monthly', SubscriptionStatus::ACTIVE, now()->addMonth(), trialEndsAt: now()->addWeek());
        $this->subscribedUser('audit-cancelled-demo@flexpick.net', 'Audit Cancelled Demo', 'audit-growth-monthly', SubscriptionStatus::CANCELED, now()->addWeeks(2));
        $this->subscribedUser('audit-expired-demo@flexpick.net', 'Audit Expired Demo', 'audit-starter-monthly', SubscriptionStatus::PAST_DUE, now()->subWeek());

        // Subscribed users get dashboard-sourced audits in a spread of statuses
        $this->seedAuditRequests($starter, count: 4, source: 'dashboard');
        $this->seedAuditRequests($growth, count: 3, source: 'dashboard');
        $this->seedAuditRequests($scale, count: 2, source: 'dashboard');

        // Free-quota users: 1 of 3 used vs 3 of 3 used
        $free = $this->user('audit-free-demo@flexpick.net', 'Audit Free Demo');
        $this->tenantFor($free);
        $this->seedAuditRequests($free, count: 1, source: 'web', freeRun: true);

        $exhausted = $this->user('audit-exhausted-demo@flexpick.net', 'Audit Exhausted Demo');
        $this->tenantFor($exhausted);
        $this->seedAuditRequests($exhausted, count: 3, source: 'web', freeRun: true);
    }

    private function user(string $email, string $name): User
    {
        return User::where('email', $email)->first()
            ?? User::factory()->create(['email' => $email, 'name' => $name, 'password' => bcrypt(self::PASSWORD)]);
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = $user->tenants()->first();

        if ($tenant === null) {
            $tenant = Tenant::factory()->create(['created_by' => $user->id]);
            $tenant->users()->attach($user);
            $this->tenantPermissionService->assignTenantUserRole($tenant, $user, TenancyPermissionConstants::ROLE_ADMIN);
        }

        return $tenant;
    }

    private function subscribedUser(
        string $email,
        string $name,
        string $planSlug,
        SubscriptionStatus $status,
        CarbonInterface $endsAt,
        ?CarbonInterface $trialEndsAt = null,
    ): User {
        $user = $this->user($email, $name);
        $tenant = $this->tenantFor($user);
        $plan = Plan::where('slug', $planSlug)->firstOrFail();
        $price = $plan->prices()->firstOrFail();
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();

        $subscription = $user->subscriptions()->where('plan_id', $plan->id)->first();

        if ($subscription === null) {
            $user->subscriptions()->create([
                'uuid' => Str::uuid(),
                'plan_id' => $plan->id,
                'price' => $price->price,
                'currency_id' => $price->currency_id,
                'status' => $status->value,
                'ends_at' => $endsAt,
                'trial_ends_at' => $trialEndsAt,
                'payment_provider_id' => $stripe->id,
                'interval_id' => $plan->interval_id,
                'interval_count' => $plan->interval_count,
                'tenant_id' => $tenant->id,
            ]);
        } else {
            $subscription->update(['status' => $status->value, 'ends_at' => $endsAt, 'trial_ends_at' => $trialEndsAt]);
        }

        return $user;
    }

    private function seedAuditRequests(User $user, int $count, string $source, bool $freeRun = false): void
    {
        $statuses = [
            AuditRequestStatus::SENT,
            AuditRequestStatus::ANALYZING,
            AuditRequestStatus::QUEUED,
            AuditRequestStatus::FAILED,
            AuditRequestStatus::AWAITING_ACCESS,
        ];

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i % count($statuses)];

            $request = AuditRequest::updateOrCreate(
                [
                    'email' => $user->email,
                    'repo_url' => 'https://github.com/flexpick-demo/'.Str::slug($user->name).'-repo-'.($i + 1),
                ],
                [
                    'name' => $user->name,
                    'message' => 'Demo audit request seeded for manual testing.',
                    'status' => $status->value,
                    'email_verified_at' => now(),
                    'free_run' => $freeRun,
                    'source' => $source,
                    'user_id' => $user->id,
                ],
            );

            if ($status === AuditRequestStatus::SENT && $request->report === null) {
                AuditReport::factory()->locked()->create([
                    'audit_request_id' => $request->id,
                    'user_id' => $user->id,
                ]);
            }
        }
    }
}
