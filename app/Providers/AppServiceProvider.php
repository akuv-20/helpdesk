<?php

namespace App\Providers;

use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiConfig;
use App\Services\Glpi\GlpiUserOAuth;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(GlpiClient::class, fn ($app) => new GlpiClient(
            GlpiConfig::resolve($app->make(Settings::class))
        ));

        $this->app->singleton(GlpiUserOAuth::class, fn ($app) => new GlpiUserOAuth(
            GlpiConfig::resolve($app->make(Settings::class))
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registra el proveedor OIDC de Microsoft/Entra para Socialite.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', \SocialiteProviders\Microsoft\Provider::class);
        });
    }
}
