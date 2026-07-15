<?php

namespace Tests\Feature\Filament\Admin\Page;

use App\Filament\Admin\Pages\AuditSettings as AuditSettingsPage;
use App\Livewire\Filament\AuditSettings;
use App\Services\ConfigService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditSettingsTest extends FeatureTest
{
    public function test_admin_can_access_audit_settings_page(): void
    {
        config(['app.admin_settings.enabled' => true]);

        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->get(AuditSettingsPage::getUrl([], true, 'admin'));

        $response->assertSuccessful();
        $response->assertSee('Audit Settings');
    }

    public function test_admin_can_save_valid_template(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => "HEAD\n{metrics}\n{excerpts}\nTAIL"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame("HEAD\n{metrics}\n{excerpts}\nTAIL", app(ConfigService::class)->get('audit.prompt_template'));
    }

    public function test_template_missing_placeholders_is_rejected(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => 'no placeholders here'])
            ->call('save')
            ->assertHasFormErrors(['prompt_template']);
    }

    public function test_blank_template_is_allowed_and_means_default(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
