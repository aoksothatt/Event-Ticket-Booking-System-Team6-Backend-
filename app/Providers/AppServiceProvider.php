<?php

namespace App\Providers;

use App\Repositories\PaymentRepository;
use App\Services\Bakong\BakongApi;
use App\Services\Bakong\BakongService;
use App\Services\Bakong\PaymentVerificationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BakongApi::class, fn () => new BakongApi(
            (string) config('bakong.base_url'),
            (int) config('bakong.timeout', 30)
        ));

        $this->app->singleton(BakongService::class, fn ($app) => new BakongService(
            $app->make(BakongApi::class)
        ));

        $this->app->bind(PaymentVerificationService::class, fn ($app) => new PaymentVerificationService(
            $app->make(BakongService::class),
            $app->make(PaymentRepository::class),
            $app->make(\App\Services\TicketService::class)
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
