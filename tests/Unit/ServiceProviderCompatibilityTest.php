<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer;
use Ronu\RestGenericClass\Core\Authorization\NullAuthorizationContext;
use Ronu\RestGenericClass\Core\Authorization\RoleFieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;

final class ServiceProviderCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RestGenericClassServiceProvider::class];
    }

    public function testProviderBootsAndRegistersOnlyTheCoreAuthorizationDefaults(): void
    {
        $this->assertFalse(config('rest-generic-class.cache.enabled'));
        $this->assertSame([
            'eager_relations' => null,
            'eager_relations_to_one' => null,
            'relation_abilities' => ['read', 'manage'],
        ], config('rest-generic-class.authorization'));
        $this->assertFalse(config()->has('rest-generic-class.permissions'), 'permission settings live in ronu/rest-permissions');

        $this->assertInstanceOf(NullAuthorizationContext::class, $this->app->make(AuthorizationContext::class));
        $this->assertInstanceOf(GateRelationAuthorizer::class, $this->app->make(RelationAuthorizer::class));
        $this->assertInstanceOf(RoleFieldWriteAuthorizer::class, $this->app->make(FieldWriteAuthorizer::class));
        $this->assertFalse(app(RelationAuthorizer::class)->enabled(), 'eager_relations defaults to off');

        // No Gate::before and no permission routes are registered by the core.
        $user = new class {
            public function effectivePermissions(?string $guard = null): \Illuminate\Support\Collection
            {
                return collect([(object) ['name' => 'optional.permission']]);
            }
        };
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('optional.permission'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('rest-generic-class.permissions.authenticated'));
    }

    public function testAnIntegrationBindingWinsOverTheCoreDefaults(): void
    {
        $context = new class implements AuthorizationContext {
            public function current(?\Illuminate\Http\Request $request = null): ?\Ronu\RestGenericClass\Core\Authorization\AuthorizationScope
            {
                return null;
            }
        };
        $this->app->bind(AuthorizationContext::class, fn () => $context);
        (new RestGenericClassServiceProvider($this->app))->register();
        $this->assertSame($context, $this->app->make(AuthorizationContext::class));
    }
}
