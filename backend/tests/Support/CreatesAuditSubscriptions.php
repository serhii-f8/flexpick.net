<?php

namespace Tests\Support;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

trait CreatesAuditSubscriptions
{
    /** @return array{0: User, 1: Tenant} */
    protected function userWithAllowance(int $diagnostic, int $deepAi = 0, int $expert = 0): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $product = Product::factory()->create(['metadata' => [
            'audit_diagnostic_credits' => $diagnostic,
            'audit_deep_ai_credits' => $deepAi,
            'audit_expert_credits' => $expert,
        ]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return [$user, $tenant];
    }

    /** The dashboard panel is tenant-scoped; a page will not mount without this. */
    protected function actAsTenantUser(User $user, ?Tenant $tenant = null): void
    {
        if ($tenant === null) {
            $tenant = Tenant::factory()->create();
            $tenant->users()->attach($user);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);
    }
}
