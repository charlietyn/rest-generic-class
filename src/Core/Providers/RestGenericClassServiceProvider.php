<?php

namespace Ronu\RestGenericClass\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Ronu\RestGenericClass\Core\Authorization\AuthorizationConfig;
use Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer;
use Ronu\RestGenericClass\Core\Authorization\MissingAuthorizationContextException;
use Ronu\RestGenericClass\Core\Authorization\NullAuthorizationContext;
use Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer;
use Ronu\RestGenericClass\Core\Authorization\RoleFieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;

class RestGenericClassServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'rest-generic-class');
        $this->registerAuthorizationContracts();
    }

    public function boot(): void
    {
        $this->assertAuthorizationContextWhenRequired();

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

    /**
     * Core authorization seams. bindIf/singletonIf: a permission integration (for example
     * ronu/rest-permissions) may bind its own implementation before or after this provider.
     */
    private function registerAuthorizationContracts(): void
    {
        $this->app->bindIf(AuthorizationContext::class, NullAuthorizationContext::class);
        $this->app->bindIf(RelationAuthorizer::class, GateRelationAuthorizer::class);
        $this->app->singletonIf(FieldWriteAuthorizer::class, RoleFieldWriteAuthorizer::class);
        $this->app->singletonIf(RelationAbilityNamer::class);
    }

    /**
     * require_read with nothing that provides the request scope would leave relations unchecked.
     * Fail at boot for HTTP; console commands (composer package:discover, route:list, ...) keep
     * running and the check fails closed at first use instead.
     */
    private function assertAuthorizationContextWhenRequired(): void
    {
        if ($this->app->runningInConsole()
            || AuthorizationConfig::eagerRelations() !== AuthorizationConfig::REQUIRE_READ) {
            return;
        }
        if ($this->app->make(AuthorizationContext::class) instanceof NullAuthorizationContext) {
            throw MissingAuthorizationContextException::forEagerRelations();
        }
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../../config/rest-generic-class.php';
    }
}
