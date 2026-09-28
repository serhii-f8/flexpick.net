<?php

namespace Tests\Feature\Filament\Admin\Page;

use App\Filament\Admin\Pages\AuditSettings as AuditSettingsPage;
use App\Livewire\Filament\AuditSettings;
use App\Models\Config;
use App\Services\AuditReport\AuditSizeBands;
use App\Services\ConfigService;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTest;

class AuditSettingsTest extends FeatureTest
{
    private ?\Closure $undoRepeaterFake = null;

    protected function tearDown(): void
    {
        // The fake is static; undo it even when an assertion failed above
        // it, or every later test in the process runs faked.
        if ($this->undoRepeaterFake !== null) {
            ($this->undoRepeaterFake)();
            $this->undoRepeaterFake = null;
        }

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
        config(['app.admin_settings.enabled' => true]);
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
        config(['app.admin_settings.enabled' => true]);
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => 'no placeholders here'])
            ->call('save')
            ->assertHasFormErrors(['prompt_template']);
    }

    public function test_blank_template_is_allowed_and_means_default(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['prompt_template' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_admin_can_save_size_bands(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $this->undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [
                ['max_loc' => 70001, 'runs' => 1],
                ['max_loc' => 210003, 'runs' => 3],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [['max_loc' => 70001, 'runs' => 1], ['max_loc' => 210003, 'runs' => 3]],
            app(AuditSizeBands::class)->bands(),
        );
    }

    public function test_the_form_opens_with_the_active_bands(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $this->undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->assertFormSet(['size_bands' => AuditSizeBands::DEFAULT_BANDS]);
    }

    public function test_bands_where_a_bigger_repo_costs_fewer_runs_are_rejected(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $this->undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [
                ['max_loc' => 1000, 'runs' => 2],
                ['max_loc' => 5000, 'runs' => 1],
            ]])
            ->call('save')
            ->assertHasFormErrors(['size_bands']);

        $this->assertSame(AuditSizeBands::DEFAULT_BANDS, app(AuditSizeBands::class)->bands());
    }

    public function test_duplicate_size_limits_are_rejected(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $this->undoRepeaterFake = Repeater::fake();

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [
                ['max_loc' => 1000, 'runs' => 1],
                ['max_loc' => 1000, 'runs' => 2],
            ]])
            ->call('save')
            ->assertHasFormErrors(['size_bands']);

        $this->assertSame(AuditSizeBands::DEFAULT_BANDS, app(AuditSizeBands::class)->bands());
    }

    public function test_a_signed_out_visitor_cannot_open_the_audit_settings_component(): void
    {
        config(['app.admin_settings.enabled' => true]);

        try {
            Livewire::test(AuditSettings::class);
            $this->fail('The component must refuse a signed-out caller.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_user_without_the_settings_permission_cannot_open_the_component(): void
    {
        config(['app.admin_settings.enabled' => true]);

        try {
            Livewire::actingAs($this->createUser())
                ->test(AuditSettings::class);
            $this->fail('The component must refuse a caller without "update settings".');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_saving_a_crafted_payload_without_size_bands_leaves_the_stored_bands_untouched(): void
    {
        config(['app.admin_settings.enabled' => true]);
        $this->undoRepeaterFake = Repeater::fake();
        $admin = $this->createAdminUser();

        $component = Livewire::actingAs($admin)
            ->test(AuditSettings::class)
            ->fillForm(['size_bands' => [['max_loc' => 70001, 'runs' => 1]]])
            ->call('save')
            ->assertHasNoFormErrors();

        // A direct /livewire/update call can drop size_bands from the payload
        // entirely; absent attributes skip validation, so the save must leave
        // the stored bands alone rather than silently write the defaults.
        $component->fill(['data' => ['prompt_template' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [['max_loc' => 70001, 'runs' => 1]],
            app(AuditSizeBands::class)->bands(),
        );
    }
}
