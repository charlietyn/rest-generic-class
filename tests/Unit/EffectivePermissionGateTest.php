<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ronu\RestGenericClass\Core\Support\Authorization\EffectivePermissionGate;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelUser;

final class EffectivePermissionGateTest extends ChannelAuthorizationTestCase
{
    public function testTraitGrantsDirectAndGlobalPermissionsWithGuardFilteringAndPolicyFallback(): void
    {
        $user = new ChannelUser();
        $user->id = 7;
        $direct = $this->permission('admin.users.index');
        $user->enabled_permissions()->attach($direct->id);
        $this->permission('admin.auth.permissions', restrict: false);
        $this->permission('web.only', 'web', false);
        $this->permission('admin.secret');
        $gate = app(EffectivePermissionGate::class);

        $this->assertTrue($user->can('admin.users.index'));
        $this->assertTrue($user->can('admin.auth.permissions'));
        $this->assertNull($gate->allows($user, 'admin.secret'));
        $this->assertFalse($user->can('web.only'));
        Gate::define('policy.allowed', fn ($user) => true);
        $this->assertTrue($user->can('policy.allowed'));
        $this->assertSame(['admin.users.index', 'admin.auth.permissions'], $user->effectivePermissions('api')->pluck('name')->all());
    }

    public function testGlobalUnionIsOptInAndIncludesUsersWithoutRoles(): void
    {
        $user = new ChannelUser();
        $user->id = 1;
        $this->permission('global.api', restrict: false);
        $this->permission('global.web', 'web', false);
        $this->assertCount(2, $user->effectivePermissions());
        config(['rest-generic-class.permissions.authorization.register_gate' => false]);
        $this->assertCount(0, $user->effectivePermissions());
    }

    public function testGateDoesNotInterfereWithSpatieOrUnrelatedModels(): void
    {
        $user = new class extends ChannelUser {
            public function checkPermissionTo(): bool { return false; }
            public function effectivePermissions(?string $guard = null): \Illuminate\Support\Collection
            {
                throw new \LogicException('Spatie users must not enter the fallback');
            }
        };
        $gate = app(EffectivePermissionGate::class);
        $this->assertNull($gate->allows($user, 'anything'));
        $this->assertNull($gate->allows(new \stdClass(), 'anything'));
    }

    public function testGlobalScopeIncludesNullRestrictionsAndDeduplicatesAssignedGlobals(): void
    {
        $user = new ChannelUser();
        $user->id = 1;
        $permission = $this->permission('global.permission', restrict: false);
        $permission->update(['restrict' => null]);
        $user->enabled_permissions()->attach($permission->id);
        $this->assertSame(['global.permission'], $user->effectivePermissions('api')->pluck('name')->all());
    }

    public function testCacheAvoidsRepeatedReadsAndClearsBetweenRequestsAndScopes(): void
    {
        $user = new ChannelUser();
        $user->id = 1;
        $permission = $this->permission('admin.users.index');
        $user->enabled_permissions()->attach($permission->id);
        $gate = app(EffectivePermissionGate::class);
        DB::enableQueryLog();
        $this->assertTrue($gate->allows($user, 'admin.users.index'));
        $queries = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queries);
        $this->assertNull($gate->allows($user, 'missing'));
        $this->assertTrue($gate->allows($user, 'admin.users.index'));
        $this->assertCount($queries, DB::getQueryLog());
        $user->enabled_permissions()->detach();
        app('events')->dispatch(new RequestHandled(Request::create('/'), new Response()));
        $this->assertNull($gate->allows($user, 'admin.users.index'));
        app()->forgetScopedInstances();
        $this->assertNotSame($gate, app(EffectivePermissionGate::class));
    }

    public function testCacheIsolatesUserObjectsAndGuards(): void
    {
        $user = new ChannelUser();
        $user->id = 1;
        $other = new ChannelUser();
        $other->id = 2;
        $permission = $this->permission('direct');
        $user->enabled_permissions()->attach($permission->id);
        $gate = app(EffectivePermissionGate::class);
        $this->assertTrue($gate->allows($user, 'direct'));
        $this->assertNull($gate->allows($other, 'direct'));
        config(['rest-generic-class.permissions.authorization.gate_guard' => 'web']);
        $this->assertNull($gate->allows($user, 'direct'));
    }
}
