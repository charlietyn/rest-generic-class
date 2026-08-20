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
        $this->assertTrue($this->app->bound(PermissionCompressorContract::class));
        $this->assertTrue($this->app->bound(UserRolesResolver::class));
        $this->assertSame(
            $this->app->make(UserRolesResolver::class),
            $this->app->make(UserRolesResolver::class)
        );
    }
}
