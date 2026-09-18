<?php

namespace App\Http\Controllers\Auth\Trait;

use App\Models\User;
use App\Services\UserDashboardService;
use Illuminate\Support\Facades\Redirect;

trait RedirectAwareTrait
{
    /**
     * Where a freshly signed-in user goes: their workspace dashboard (or the
     * admin panel), resolved here rather than through /dashboard so there is
     * no extra hop. An intended URL only exists when the auth middleware
     * bounced them off a protected page -- that page wins.
     */
    protected function getRedirectUrl(?User $user): string
    {
        if (! $user) {
            return route('home');
        }

        if (Redirect::getIntendedUrl() !== null && rtrim(Redirect::getIntendedUrl(), '/') !== rtrim((route('home')), '/')) {
            return Redirect::getIntendedUrl();
        }

        if ($user->is_admin) {
            return route('filament.admin.pages.dashboard');
        }

        return app(UserDashboardService::class)->getUserDashboardUrl($user);
    }
}
