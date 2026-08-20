<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditTier;
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Models\AuditRequest;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestTierTest extends FeatureTest
{
    public function test_an_operator_can_change_a_requests_tier(): void
    {
        $admin = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAuditRequest::class, ['record' => $request->uuid])
            ->fillForm([
                'status' => $request->status,
                'name' => $request->name,
                'email' => $request->email,
                'tier' => AuditTier::DEEP_AI->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AuditTier::DEEP_AI, $request->fresh()->tier);
    }

    public function test_the_tier_select_options_show_price(): void
    {
        $admin = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAuditRequest::class, ['record' => $request->uuid])
            ->assertSee(AuditTier::EXPERT->labelWithPrice())
            ->assertSee(AuditTier::DIAGNOSTIC->labelWithPrice());
    }
}
