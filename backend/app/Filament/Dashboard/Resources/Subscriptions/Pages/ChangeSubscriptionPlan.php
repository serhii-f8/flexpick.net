<?php

namespace App\Filament\Dashboard\Resources\Subscriptions\Pages;

use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

class ChangeSubscriptionPlan extends Page
{
    protected static string $resource = SubscriptionResource::class;

    protected string $view = 'filament.dashboard.resources.subscription-resource.pages.change-subscription-plan';

    public function mount(): void
    {
        $subscriptionService = app(SubscriptionService::class);
        $subscription = $subscriptionService->findActiveByTenantAndSubscriptionUuid(
            Filament::getTenant(),
            (string) request()->route('record'),
        );

        abort_if($subscription === null, 404);
        abort_unless($subscriptionService->canChangeSubscriptionPlan($subscription), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Change plan');
    }

    public function getBreadcrumb(): ?string
    {
        return __('Change plan');
    }

    protected function getViewData(): array
    {
        $route = Route::current();
        $subscriptionUuid = $route->parameters['record'];

        return array_merge(parent::getViewData(), [
            'subscriptionUuid' => $subscriptionUuid,
        ]);
    }
}
