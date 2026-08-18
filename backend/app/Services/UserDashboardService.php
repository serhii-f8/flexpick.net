<?php

namespace App\Services;

use App\Models\User;

class UserDashboardService
{
    public function getUserDashboardUrl(User $user): string
    {
        $tenant = $user->tenants()->orderByPivot('is_default', 'desc')->first();

        if ($tenant !== null) {
            return route('filament.dashboard.pages.dashboard', ['tenant' => $tenant]);
        }

        // An admin has no tenant to show a customer dashboard for -- send
        // them to the panel they actually operate instead of the landing
        // page, which reads as "/dashboard is broken" more than "logged in
        // as an operator, not a customer".
        if ($user->isAdmin()) {
            return route('filament.admin.pages.dashboard');
        }

        return route('home');
    }
}
