<?php

namespace Ronu\RestGenericClass\Core\Providers;

use Illuminate\Support\ServiceProvider;

class RestGenericClassServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'rest-generic-class');
    }

    public function boot(): void
    {
        $this->publishes([
            $this->configPath() => config_path('rest-generic-class.php'),
        ], 'rest-generic-class-config');

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
}
