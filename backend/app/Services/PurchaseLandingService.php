<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;

/**
 * Where a buyer goes once checkout has succeeded: the dashboard of the
 * workspace the purchase was made for, with the thank-you carried as a
 * Filament notification (persisted in the session, shown by the panel on
 * the next page) instead of a dead-end thank-you page.
 */
class PurchaseLandingService
{
    public function __construct(
        private UserDashboardService $userDashboardService,
    ) {}

    public function redirect(User $user, ?Tenant $tenant, string $body): RedirectResponse
    {
        Notification::make()
            ->title(__('Thank you for your purchase!'))
            ->body($body)
            ->success()
            ->send();

        $url = $tenant !== null && $user->tenants()->whereKey($tenant->id)->exists()
            ? route('filament.dashboard.pages.dashboard', ['tenant' => $tenant])
            : $this->userDashboardService->getUserDashboardUrl($user);

        return redirect($url);
    }
}
