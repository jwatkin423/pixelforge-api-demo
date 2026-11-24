<?php

namespace App\Providers;

use App\Billing\CostCalculator;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClientService;
use App\Services\Pixelforge\WebhookSignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClientService::class, fn (): PixelforgeClientService => new PixelforgeClientService(
            baseUrl: (string) config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
            timeoutSeconds: (int) config('services.pixelforge.timeout_seconds'),
        ));

        $this->app->bind(WebhookSignatureService::class, fn (): WebhookSignatureService => new WebhookSignatureService(
            secret: (string) config('services.pixelforge.webhook_secret'),
            toleranceSeconds: (int) config('services.pixelforge.webhook_tolerance_seconds'),
        ));

        $this->app->bind(CostCalculator::class, fn (): CostCalculator => new CostCalculator(
            markupPercent: (int) config('billing.markup_percent'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('view-admin', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
