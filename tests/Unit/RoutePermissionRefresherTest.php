<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;
use Ronu\RestGenericClass\Core\Resolvers\RouteMeta;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;
use Ronu\RestGenericClass\Core\Support\Permissions\RoutePermissionRefresher;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;

final class RoutePermissionRefresherTest extends ChannelAuthorizationTestCase
{
    public function testSameNameWithDifferentGuardsIsNotOverwrittenAndSyncIsIdempotent(): void
    {
        $web = $this->permission('admin.users.index', 'web');
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        Route::get('client/users', fn () => 'ok')->name('client.users.index');
        $refresher = new RoutePermissionRefresher();
        $rows = $refresher->refresh('api', false);
        $this->assertCount(2, $rows);
        $this->assertSame('web', $web->fresh()->guard_name);
        $this->assertNull($web->fresh()->route);
        $this->assertSame(3, ChannelPermission::count());
        $refresher->refresh('api', false);
        $this->assertSame(3, ChannelPermission::count());
        $api = ChannelPermission::where('guard_name', 'api')->where('name', 'admin.users.index')->firstOrFail();
        $this->assertSame('admin/users', $api->route);
        $this->assertFalse((bool) $api->restrict);
    }

    public function testDryRunDoesNotPersistOrForgetCache(): void
    {
        Route::get('admin/auth/permissions', fn () => 'ok')->name('admin.auth.permissions');
        cache()->put('spatie.permission.cache', 'unchanged');
        $rows = (new RoutePermissionRefresher())->refresh('api', true);
        $this->assertSame('admin.auth.permissions', $rows[0]['permission']);
        $this->assertSame(0, ChannelPermission::count());
        $this->assertSame('unchanged', cache()->get('spatie.permission.cache'));
    }

    public function testDisabledExplicitGlobalsPreserveModuleBasedRestrictions(): void
    {
        $this->useKnownModules();
        config(['rest-generic-class.permissions.authorization.explicit_globals' => false]);
        Route::get('admin/auth/permissions', fn () => 'ok')->name('admin.auth.permissions');
        Route::get('admin/mod_security/users', fn () => 'ok')->name('admin.mod_security.users.index');

        (new RoutePermissionRefresher())->refresh('api', false);

        $this->assertRestrictions(['admin.auth.permissions' => false, 'admin.mod_security.users.index' => true]);
    }

    public function testDefaultNullOptionsPreserveTheV250LegacyCatalog(): void
    {
        $this->useKnownModules();
        $defaults = require __DIR__ . '/../../config/rest-generic-class.php';
        config(['rest-generic-class.permissions.authorization' => $defaults['permissions']['authorization']]);
        Route::prefix('api')->group(function () {
            Route::get('mod_security/users', 'UsersController@index');
            Route::get('ModSecurity/users', 'UsersController@index');
        });
        $rows = (new RoutePermissionRefresher())->refresh('api', false);
        $this->assertSame(['users.index', 'ModSecurity.users.index'], array_column($rows, 'permission'));
        $this->assertRestrictions(['users.index' => false, 'ModSecurity.users.index' => true]);
    }

    public function testExplicitGlobalsUseGroupAttributesAndResyncExistingRows(): void
    {
        $this->useKnownModules();
        Route::group(['prefix' => 'admin', 'as' => 'admin.', 'permission_global' => true], function () {
            Route::get('auth/permissions', fn () => 'ok')->name('auth.permissions');
            Route::get('mod_security/users', fn () => 'ok')->name('mod_security.users.index');
        });
        Route::get('admin/auth/user-profile', fn () => 'ok')->name('admin.auth.user-profile');
        Route::get('admin/other/thing', fn () => 'ok')->name('admin.other.thing');
        $refresher = new RoutePermissionRefresher();
        $refresher->refresh('api', false);
        $ids = ChannelPermission::orderBy('id')->pluck('id')->all();
        $this->assertRestrictions([
            'admin.auth.permissions' => false,
            'admin.mod_security.users.index' => true,
            'admin.auth.user-profile' => false,
            'admin.other.thing' => false,
        ]);

        config(['rest-generic-class.permissions.authorization.explicit_globals' => true]);
        $refresher->refresh('api', false);
        $this->assertRestrictions([
            'admin.auth.permissions' => false,
            'admin.mod_security.users.index' => false,
            'admin.auth.user-profile' => true,
            'admin.other.thing' => true,
        ]);
        $this->assertSame($ids, ChannelPermission::orderBy('id')->pluck('id')->all());
    }

    public function testExplicitGlobalsWithoutMarkedRoutesRestrictEverything(): void
    {
        Route::get('admin/auth/permissions', fn () => 'ok')->name('admin.auth.permissions');
        $refresher = new RoutePermissionRefresher();
        $refresher->refresh('api', false);
        config(['rest-generic-class.permissions.authorization.explicit_globals' => true]);
        $refresher->refresh('api', false);
        $this->assertRestrictions(['admin.auth.permissions' => true]);
    }

    public function testStandaloneActionAttributeIsGlobalButDefaultsAreNot(): void
    {
        config(['rest-generic-class.permissions.authorization.explicit_globals' => true]);
        $route = Route::get('admin/auth/permissions', fn () => 'ok')->name('admin.auth.permissions');
        $route->setAction([...$route->getAction(), 'permission_global' => true]);
        Route::get('admin/auth/user-profile', fn () => 'ok')->name('admin.auth.user-profile')->defaults('permission_global', true);
        (new RoutePermissionRefresher())->refresh('api', false);
        $this->assertRestrictions(['admin.auth.permissions' => false, 'admin.auth.user-profile' => true]);
    }

    public function testGlobalGroupAttributeDoesNotBecomeAControllerParameter(): void
    {
        Route::group(['prefix' => 'admin', 'permission_global' => true], function () {
            Route::get('users/{id}', fn ($id) => $id)->name('admin.users.show');
        });
        $request = \Illuminate\Http\Request::create('/admin/users/42');
        $route = Route::getRoutes()->match($request);
        $this->assertTrue($route->getAction('permission_global'));
        $this->assertSame(['id' => '42'], $route->parameters());
        $this->assertSame('42', $route->run());
        $meta = app(RouteMetaResolver::class)->resolveFromRoute($route, 'api', [], config('rest-generic-class.permissions.authorization'));
        $this->assertTrue($meta->global);
    }

    public function testNullCatalogMiddlewareIncludesRoutesWithoutMiddleware(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => null]);
        Route::get('admin/auth/login', fn () => 'ok')->name('admin.auth.login');
        $rows = (new RoutePermissionRefresher())->refresh('api', false);
        $this->assertSame(['admin.auth.login'], array_column($rows, 'permission'));
        $this->assertSame(1, ChannelPermission::count());
    }

    public function testCatalogMatchesAliasesWithParametersWithoutPruningExistingPermissions(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => ['maybe.permission']]);
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index')->middleware('maybe.permission:api');
        Route::get('admin/auth/login', fn () => 'ok')->name('admin.auth.login');
        $existing = $this->permission('admin.auth.login');

        $refresher = new RoutePermissionRefresher();
        $dryRows = $refresher->refresh('api', true);
        $this->assertSame(1, ChannelPermission::count());
        $rows = $refresher->refresh('api', false);
        $this->assertSame($dryRows, $rows);
        $this->assertSame(['admin.users.index'], array_column($rows, 'permission'));
        $this->assertSame(2, ChannelPermission::count());
        $this->assertNotNull($existing->fresh());
        $this->assertNull($existing->fresh()->route);
    }

    public function testCatalogAcceptsMultipleMarkersAndMiddlewareClassNames(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => ['maybe.permission', SpatieAuthorize::class]]);
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index')->middleware(SpatieAuthorize::class . ':api');
        Route::get('client/users', fn () => 'ok')->name('client.users.index')->middleware('maybe.permission:api');
        Route::get('admin/auth/login', fn () => 'ok')->name('admin.auth.login')->middleware('auth:api');
        $rows = (new RoutePermissionRefresher())->refresh('api', false);
        $this->assertSame(['admin.users.index', 'client.users.index'], array_column($rows, 'permission'));
        $this->assertSame(2, ChannelPermission::count());
    }

    public function testCatalogIgnoresNonStringMiddleware(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => ['maybe.permission']]);
        $closure = fn ($request, $next) => $next($request);
        $object = new \stdClass();
        $included = Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        $included->setAction(array_merge($included->getAction(), ['middleware' => [$closure, $object, 'maybe.permission:api']]));
        $excluded = Route::get('client/users', fn () => 'ok')->name('client.users.index');
        $excluded->setAction(array_merge($excluded->getAction(), ['middleware' => [$closure, $object]]));

        $rows = (new RoutePermissionRefresher())->refresh('api', false);
        $this->assertSame(['admin.users.index'], array_column($rows, 'permission'));
        $this->assertSame(1, ChannelPermission::count());
    }

    public function testEmptyCatalogMiddlewareIncludesNoRoutes(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => []]);
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index')->middleware('maybe.permission:api');
        $this->assertSame([], (new RoutePermissionRefresher())->refresh('api', false));
        $this->assertSame(0, ChannelPermission::count());
    }

    public function testCatalogFilterDoesNotChangeRequestResolution(): void
    {
        config(['rest-generic-class.permissions.authorization.catalog_middleware' => []]);
        $route = Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        $request = \Illuminate\Http\Request::create('/admin/users');
        $request->setRouteResolver(fn () => $route);
        $meta = app(RouteMetaResolver::class)->resolve($request, 'api', [], config('rest-generic-class.permissions.authorization'));
        $this->assertSame('admin.users.index', $meta->canonicalName);
        $this->assertSame([], (new RoutePermissionRefresher())->refresh('api', true));
    }

    private function useKnownModules(): void
    {
        // Exercise the real resolver without making nwidart a test dependency.
        app()->instance(RouteMetaResolver::class, new class extends RouteMetaResolver {
            public function resolveFromRoute(\Illuminate\Routing\Route $route, string $guard, array $modules = [], array $cfg = []): ?RouteMeta
            {
                return parent::resolveFromRoute($route, $guard, ['ModSecurity'], $cfg);
            }
        });
    }

    private function assertRestrictions(array $expected): void
    {
        $this->assertSame(
            $expected,
            ChannelPermission::where('guard_name', 'api')->orderBy('id')->get()
                ->mapWithKeys(fn ($permission) => [$permission->name => (bool) $permission->restrict])->all()
        );
    }
}
