<?php

namespace Ronu\RestGenericClass\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Gate;
use Ronu\RestGenericClass\Core\Authorization\AuthorizationConfig;
use Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer;
use Ronu\RestGenericClass\Core\Authorization\MissingAuthorizationContextException;
use Ronu\RestGenericClass\Core\Authorization\NullAuthorizationContext;
use Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer;
use Ronu\RestGenericClass\Core\Authorization\RoleFieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;
use Ronu\RestGenericClass\Core\Support\Authorization\RouteAuthorizationContext;
use Ronu\RestGenericClass\Core\Support\Authorization\EffectivePermissionGate;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Support\Permissions\Exceptions\RolesContractViolationException;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionCompressor;
use Ronu\RestGenericClass\Core\Support\Permissions\UserRolesResolver;

class RestGenericClassServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'rest-generic-class');
        $this->registerAuthorizationContracts();
        $this->app->singleton(PermissionCompressorContract::class, PermissionCompressor::class);
        $this->app->singleton(UserRolesResolver::class);
        $this->app->scoped(EffectivePermissionGate::class);
    }

    public function boot(): void
    {
        $this->assertAuthorizationContextWhenRequired();

        if (config('rest-generic-class.permissions.authorization.register_gate', false)) {
            Gate::before(static fn ($user, string $ability) => app(EffectivePermissionGate::class)->allows($user, $ability));
            $this->app['events']->listen(RequestHandled::class, function (): void {
                if ($this->app->resolved(EffectivePermissionGate::class)) {
                    $this->app->make(EffectivePermissionGate::class)->clear();
                }
            });
        }

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

        $this->assertContractsIfDeclared();
    }

    /**
     * Core authorization seams. bindIf/singletonIf: a permission integration may bind its own
     * implementation before or after this provider registers.
     */
    private function registerAuthorizationContracts(): void
    {
        // The route permissions shipped with this package provide the request scope (v2.9).
        $this->app->bindIf(AuthorizationContext::class, RouteAuthorizationContext::class);
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

    /**
     * Optional fail-fast validation. If the integrator declared the user/role model FQCNs
     * in config, verify they implement the required contracts at boot time.
     * Otherwise the contracts are still enforced lazily by UserRolesResolver on first use.
     */
    private function assertContractsIfDeclared(): void
    {
        $userModel = config('rest-generic-class.permissions.contracts.user_model');
        if ($userModel && !is_subclass_of($userModel, ProvidesRoles::class)) {
            throw RolesContractViolationException::userMissingContract($userModel);
        }

        $roleModel = config('rest-generic-class.permissions.contracts.role_model');
        if ($roleModel && !is_subclass_of($roleModel, ProvidesRolePermissions::class)) {
            throw RolesContractViolationException::roleMissingContract($roleModel);
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
