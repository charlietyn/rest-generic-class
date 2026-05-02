<?php

namespace Ronu\RestGenericClass\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionCompressor;

class RestGenericClassServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'rest-generic-class');
        $this->app->singleton(PermissionCompressorContract::class, PermissionCompressor::class);
    }

    public function boot(): void
    {
        $this->publishes([
            $this->configPath() => config_path('rest-generic-class.php'),
        ], 'rest-generic-class-config');

        if (config('rest-generic-class.permissions.routes.enabled', false)) {
            $this->loadRoutesFrom($this->routesPath());
        }

        if (!config()->has('logging.channels.rest-generic-class')) {
            config([
                'logging.channels.rest-generic-class' => [
                    'driver' => config('rest-generic-class.logging.channel.driver', 'single'),
                    'path' => config('rest-generic-class.logging.channel.path', storage_path('logs/rest-generic-class.log')),
                    'level' => config('rest-generic-class.logging.channel.level', 'debug'),
                ],
            ]);
        }
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../../config/rest-generic-class.php';
    }

    private function routesPath(): string
    {
        return __DIR__ . '/../../../routes/permissions.php';
    }
}
