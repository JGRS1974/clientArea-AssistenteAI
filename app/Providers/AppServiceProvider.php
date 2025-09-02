<?php

namespace App\Providers;

use App\Tools\TicketTool;
use App\Services\ApiConsumerService;
use App\Services\PinGeneratorService;
use App\Services\RedisConversationService;
use App\Services\SessionIdService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->singleton(TicketTool::class);

        $this->app->singleton(ApiConsumerService::class, function ($app) {
            return new ApiConsumerService();
        });

        $this->app->singleton(PinGeneratorService::class, function ($app) {
            return new PinGeneratorService();
        });

        $this->app->singleton(SessionIdService::class, function ($app) {
            return new SessionIdService();
        });

         $this->app->singleton(RedisConversationService::class, function ($app) {
            return new RedisConversationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
