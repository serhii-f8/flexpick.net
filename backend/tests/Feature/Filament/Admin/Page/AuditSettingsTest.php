<?php

namespace Tests\Feature\Filament\Admin\Page;

use App\Filament\Admin\Pages\AuditSettings as AuditSettingsPage;
use App\Livewire\Filament\AuditSettings;
use App\Models\Config;
use App\Services\AuditReport\AuditSizeBands;
use App\Services\ConfigService;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditSettingsTest extends FeatureTest
{
    protected function tearDown(): void
    {
        // No per-test DB reset in this suite; a saved setting would leak
        // into every test that runs afterwards.
        Config::where('key', 'audit.size_bands')->delete();
        Config::where('key', 'audit.prompt_template')->delete();

        parent::tearDown();
    }

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
            ->fillForm(['prompt_template' => "HEAD\n{metrics}\n{groups}\n{excerpts}\nTAIL"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame("HEAD\n{metrics}\n{groups}\n{excerpts}\nTAIL", app(ConfigService::class)->get('audit.prompt_template'));
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

    public function test_admin_can_save_size_bands(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [
                ['max_loc' => 70001, 'runs' => 1],
                ['max_loc' => 210003, 'runs' => 3],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertSame(
            [['max_loc' => 70001, 'runs' => 1], ['max_loc' => 210003, 'runs' => 3]],
            app(AuditSizeBands::class)->bands(),
        );
    }

    public function test_the_form_opens_with_the_active_bands(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->assertFormSet(['size_bands' => AuditSizeBands::DEFAULT_BANDS]);

        $undoRepeaterFake();
    }

    public function test_bands_where_a_bigger_repo_costs_fewer_runs_are_rejected(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [
                ['max_loc' => 1000, 'runs' => 2],
                ['max_loc' => 5000, 'runs' => 1],
            ]])
            ->call('save')
            ->assertHasFormErrors(['size_bands']);

        $undoRepeaterFake();

        $this->assertSame(AuditSizeBands::DEFAULT_BANDS, app(AuditSizeBands::class)->bands());
    }
}
