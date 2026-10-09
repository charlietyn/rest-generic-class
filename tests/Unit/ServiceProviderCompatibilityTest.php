<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
use Ronu\RestGenericClass\Core\Support\Permissions\UserRolesResolver;

final class ServiceProviderCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RestGenericClassServiceProvider::class];
    }

    public function testProviderBootsAndRegistersItsPublicBindings(): void
    {
        $this->assertFalse(config('rest-generic-class.cache.enabled'));
        $this->assertSame([
            'route_prefixes' => null,
            'naming' => 'derived',
            'on_unresolved' => 'allow',
            'register_gate' => false,
            'gate_guard' => null,
            'global_permissions' => null,
            'catalog_middleware' => null,
        ], config('rest-generic-class.permissions.authorization'));
        $user = new class {
            public function effectivePermissions(?string $guard = null): \Illuminate\Support\Collection
            {
                return collect([(object) ['name' => 'optional.permission']]);
            }
        };
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('optional.permission'));
        $this->assertTrue($this->app->bound(PermissionCompressorContract::class));
        $this->assertTrue($this->app->bound(UserRolesResolver::class));
        $this->assertSame(
            $this->app->make(UserRolesResolver::class),
            $this->app->make(UserRolesResolver::class)
        );
    }
}
