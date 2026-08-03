<?php

namespace App\Providers;

use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\ClaudeAnalyzer;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\Collectors\GitFactsCollector;
use App\Services\AuditReport\Collectors\HotspotCollector;
use App\Services\AuditReport\Collectors\ManifestCollector;
use App\Services\AuditReport\Collectors\ToolingCollector;
use App\Services\AuditReport\MetricsCollector;
use App\Services\AuditReport\Scanners\GitleaksScanner;
use App\Services\AuditReport\Scanners\JscpdScanner;
use App\Services\AuditReport\Scanners\OsvScanner;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Scanners\SemgrepScanner;
use App\Services\PaymentProviders\Creem\CreemProvider;
use App\Services\PaymentProviders\LemonSqueezy\LemonSqueezyProvider;
use App\Services\PaymentProviders\Offline\OfflineProvider;
use App\Services\PaymentProviders\Paddle\PaddleProvider;
use App\Services\PaymentProviders\PaymentService;
use App\Services\PaymentProviders\Polar\PolarProvider;
use App\Services\PaymentProviders\Stripe\StripeProvider;
use App\Services\UserVerificationService;
use App\Services\VerificationProviders\TwilioProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // payment providers
        $this->app->tag([
            StripeProvider::class,
            PaddleProvider::class,
            LemonSqueezyProvider::class,
            CreemProvider::class,
            PolarProvider::class,
            OfflineProvider::class,
        ], 'payment-providers');

        $this->app->bind(PaymentService::class, function () {
            return new PaymentService(...$this->app->tagged('payment-providers'));
        });

        // verification providers
        $this->app->tag([
            TwilioProvider::class,
        ], 'verification-providers');

        $this->app->afterResolving(UserVerificationService::class, function (UserVerificationService $service) {
            $service->setVerificationProviders(...$this->app->tagged('verification-providers'));
        });

        $this->app->bind(AiAnalyzer::class, ClaudeAnalyzer::class);

        $this->app->bind('audit.scanner.gitleaks', GitleaksScanner::class);
        $this->app->bind('audit.scanner.semgrep', SemgrepScanner::class);
        $this->app->bind('audit.scanner.scc', SccScanner::class);
        $this->app->bind('audit.scanner.jscpd', JscpdScanner::class);
        $this->app->bind('audit.scanner.osv', OsvScanner::class);

        $this->app->bind(MetricsCollector::class, fn ($app) => new MetricsCollector([
            $app->make(GitFactsCollector::class),
            $app->make(ManifestCollector::class),
            $app->make(ToolingCollector::class),
            $app->make(HotspotCollector::class),
            $app->make(ExcerptCollector::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Js::make('components-script', __DIR__.'/../../resources/js/components.js'),
        ]);
    }
}
