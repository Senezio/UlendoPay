<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Services\Reporting\TrialBalanceService;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\ProfitLossService;
use App\Services\Reporting\CashFlowService;
use App\Services\PeriodService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TrialBalanceService::class);

        $this->app->singleton(BalanceSheetService::class, function ($app) {
            return new BalanceSheetService(
                trialBalance: $app->make(TrialBalanceService::class)
            );
        });

        $this->app->singleton(ProfitLossService::class);

        $this->app->singleton(CashFlowService::class);

        $this->app->singleton(PeriodService::class, function ($app) {
            return new PeriodService(
                trialBalance: $app->make(TrialBalanceService::class),
                balanceSheet: $app->make(BalanceSheetService::class),
                profitLoss:   $app->make(ProfitLossService::class),
                cashFlow:     $app->make(CashFlowService::class),
            );
        });
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);

        RateLimiter::for("api", function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for("auth", function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for("lookup", function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for("kyc", function (Request $request) {
            return Limit::perHour(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for("otp", function (Request $request) {
            return Limit::perMinutes(10, 3)->by($request->ip());
        });
    }
}
