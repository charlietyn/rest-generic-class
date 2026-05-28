<?php

namespace Ronu\RestGenericClass\Core\Providers;

use Illuminate\Support\ServiceProvider;
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
        $this->app->singleton(PermissionCompressorContract::class, PermissionCompressor::class);
        $this->app->singleton(UserRolesResolver::class);
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

        $this->assertContractsIfDeclared();
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
