<?php

namespace Tests\Feature\Filament;

use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class DashboardPanelBrandingTest extends FeatureTest
{
    public function test_dashboard_panel_is_flexpick_branded(): void
    {
        $panel = Filament::getPanel('dashboard');

        $this->assertSame('FlexPick', $panel->getBrandName());
        $this->assertSame(ThemeMode::Dark, $panel->getDefaultThemeMode());
        $this->assertStringContainsString('flexpick-wordmark.svg', (string) $panel->getBrandLogo());
    }
}
