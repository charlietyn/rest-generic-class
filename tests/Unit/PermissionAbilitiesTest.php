<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;
use Ronu\RestGenericClass\Core\Resolvers\RouteMeta;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PermissionAbilitiesTest extends ChannelAuthorizationTestCase
{
    private const RELATION = ['permission_requires' => ['^^show']];

    public function testWithoutAttributesAbilitiesAndRequiresMatchTheV271Resolution(): void
    {
        $meta = $this->meta($this->route('admin.crm.clients.pets.list'));
        $this->assertSame('admin.crm.clients.pets.list', $meta->canonicalName);
        $this->assertSame(['admin.crm.clients.pets.list'], $meta->abilities);
        $this->assertSame([], $meta->requires);
        $this->assertSame('list', $meta->action);

        config(['rest-generic-class.permissions.authorization.naming' => 'derived']);
        $derived = $this->meta($this->route('admin.users.index', 'admin/users', 'UsersController@index'));
        $this->assertSame([$derived->canonicalName], $derived->abilities);
        $this->assertSame([], $derived->requires);
    }

    public function testExistingRouteMetaConstructionDefaultsAbilitiesToTheCanonicalName(): void
    {
        new RouteMetaResolver();
        $meta = new RouteMeta(null, 'api/users', ['GET'], null, null, '--site--', 'users', 'index', 'users.index', '-');
        $this->assertSame(['users.index'], $meta->abilities);
        $this->assertSame([], $meta->requires);
    }

    public function testAbilityListReplacesTheLastSegmentAndRequiresAreRelativeToTheRouteName(): void
    {
        $meta = $this->meta($this->route('admin.crm.clients.pets.bulk-update', attributes: [
            'permission_ability' => ['update', 'manage'],
            'permission_requires' => ['^^show', '^read', '=admin.audit.log'],
        ]));
        $this->assertSame(['admin.crm.clients.pets.update', 'admin.crm.clients.pets.manage'], $meta->abilities);
        $this->assertSame('admin.crm.clients.pets.update', $meta->canonicalName);
        $this->assertSame('update', $meta->action);
        $this->assertSame(['admin.crm.clients.show', 'admin.crm.clients.pets.read', 'admin.audit.log'], $meta->requires);
        $this->assertSame('admin.crm.clients.pets.bulk-update', $meta->routeName);

        $single = $this->meta($this->route('admin.crm.clients.bulk-update', attributes: ['permission_ability' => 'update']));
        $this->assertSame(['admin.crm.clients.update'], $single->abilities);
    }

    public function testInvalidOrOutOfRangeAttributesMakeTheRouteUnresolvable(): void
    {
        foreach ([
            ['permission_requires' => ['^^^^^^show']],          // runs out of segments
            ['permission_requires' => ['^^^^^show']],           // removes every segment
            ['permission_requires' => ['show']],                // neither ^ nor =
            ['permission_requires' => ['^']],                   // nothing to append
            ['permission_requires' => ['=']],
            ['permission_requires' => ['^*']],
            ['permission_ability' => []],
            ['permission_ability' => ['']],
            ['permission_ability' => ['crm.read']],             // only the last segment is replaced
            ['permission_ability' => ['*']],
            ['permission_ability' => [['read']]],
        ] as $attributes) {
            $this->assertNull(
                $this->meta($this->route('admin.crm.clients.pets.list', attributes: $attributes)),
                json_encode($attributes)
            );
        }
        $this->assertNotNull($this->meta($this->route('admin.crm.clients.pets.list', attributes: ['permission_requires' => ['^^^^show']])));
    }

    public function testDerivedNamingIgnoresTheAttributes(): void
    {
        config(['rest-generic-class.permissions.authorization.naming' => 'derived']);
        $meta = $this->meta($this->route('admin.users.index', 'admin/users', 'UsersController@index', [
            'permission_ability' => ['read', 'manage'],
            'permission_requires' => ['^^show'],
        ]));
        $this->assertSame([$meta->canonicalName], $meta->abilities);
        $this->assertSame([], $meta->requires);
    }

    public function testManageGrantsEveryRelationRouteAndReadDoesNotGrantAdd(): void
    {
        $this->user(['admin.crm.clients.show', 'admin.crm.clients.pets.manage']);
        foreach (['list' => 'read', 'create' => 'add', 'bulk-update' => 'update', 'bulk-delete' => 'delete'] as $route => $ability) {
            $this->assertSame('passed', $this->handle($this->relationRoute($route, $ability))->getContent(), $route);
        }

        $this->user(['admin.crm.clients.show', 'admin.crm.clients.pets.read']);
        $this->assertSame('passed', $this->handle($this->relationRoute('list', 'read'))->getContent());
        $this->assertForbidden(fn () => $this->handle($this->relationRoute('create', 'add')));
    }

    public function testRequiresIsEnforcedEvenWithManage(): void
    {
        config(['app.debug' => true]);
        $this->user(['admin.crm.clients.pets.manage']);
        $message = $this->assertForbidden(fn () => $this->handle($this->relationRoute('list', 'read')));
        $this->assertSame('Forbidden: missing admin.crm.clients.show', $message);
    }

    public function testDebugMessageNamesTheMissingAlternatives(): void
    {
        config(['app.debug' => true]);
        $this->user(['admin.crm.clients.show']);
        $message = $this->assertForbidden(fn () => $this->handle($this->relationRoute('create', 'add')));
        $this->assertSame('Forbidden: missing one of admin.crm.clients.pets.add, admin.crm.clients.pets.manage', $message);

        $plain = $this->assertForbidden(fn () => $this->handle($this->route('admin.crm.clients.index')));
        $this->assertSame('Forbidden: admin.crm.clients.index', $plain);

        config(['app.debug' => false]);
        $this->assertSame('Forbidden', $this->assertForbidden(fn () => $this->handle($this->relationRoute('create', 'add'))));
    }

    public function testOutOfRangeRequiresIsDeniedWithOnUnresolvedDeny(): void
    {
        $this->user(['admin.crm.clients.pets.list']);
        $this->assertForbidden(fn () => $this->handle(
            $this->route('admin.crm.clients.pets.list', attributes: ['permission_requires' => ['^^^^^show']])
        ));
    }

    public function testSuccessfulAuthorizationExposesTheRouteContext(): void
    {
        $this->user(['admin.crm.clients.show', 'admin.crm.clients.pets.read']);
        $context = null;
        $this->handle($this->relationRoute('list', 'read'), function (Request $request) use (&$context) {
            $context = $request->attributes->get(SpatieAuthorize::CONTEXT_ATTRIBUTE);
            return new Response('passed');
        });
        $this->assertSame(
            ['guard' => 'web', 'action' => 'admin.crm.clients.pets.read', 'resource' => 'admin.crm.clients.pets'],
            $context
        );
    }

    public function testDocumentedNestedGroupsProduceTheExpectedActions(): void
    {
        \Illuminate\Support\Facades\Route::group(['prefix' => 'admin/crm', 'as' => 'admin.crm.'], function () {
            \Illuminate\Support\Facades\Route::group(['prefix' => 'clients/{id}/pets', 'as' => 'clients.pets.', 'permission_requires' => ['^^show']], function () {
                \Illuminate\Support\Facades\Route::group(['permission_ability' => ['read', 'manage']], function () {
                    \Illuminate\Support\Facades\Route::get('/', fn () => 'ok')->name('list');
                });
                \Illuminate\Support\Facades\Route::group(['permission_ability' => ['update', 'manage']], function () {
                    \Illuminate\Support\Facades\Route::put('/bulk', fn () => 'ok')->name('bulk-update');
                });
            });
        });
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $routes->refreshNameLookups();

        $list = $this->meta($routes->getByName('admin.crm.clients.pets.list'));
        $this->assertSame(['admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage'], $list->abilities);
        $this->assertSame(['admin.crm.clients.show'], $list->requires);
        $bulk = $this->meta($routes->getByName('admin.crm.clients.pets.bulk-update'));
        $this->assertSame(['admin.crm.clients.pets.update', 'admin.crm.clients.pets.manage'], $bulk->abilities);
    }

    private function relationRoute(string $suffix, string $ability): Route
    {
        return $this->route("admin.crm.clients.pets.$suffix", attributes: self::RELATION + [
            'permission_ability' => [$ability, 'manage'],
        ]);
    }

    private function route(string $name, string $uri = 'admin/crm/clients/{id}/pets', ?string $controller = null, array $attributes = []): Route
    {
        $route = new Route('GET', $uri, $controller ? ['controller' => $controller] : fn () => 'ok');
        $route->name($name);
        $route->setAction(array_merge($route->getAction(), $attributes));
        return $route;
    }

    private function meta(Route $route): ?RouteMeta
    {
        return (new RouteMetaResolver())->resolveFromRoute($route, 'api', [], config('rest-generic-class.permissions.authorization'));
    }

    private function user(array $permissions): ChannelUser
    {
        $user = new ChannelUser();
        $user->id = random_int(1, PHP_INT_MAX);
        foreach ($permissions as $name) {
            $permission = ChannelPermission::firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['restrict' => true]);
            $user->enabled_permissions()->attach($permission->id);
        }
        $this->actingAs($user, 'web');
        return $user;
    }

    private function handle(Route $route, ?\Closure $next = null): Response
    {
        $request = Request::create('/' . $route->uri());
        $request->setRouteResolver(fn () => $route);
        $middleware = (new \ReflectionClass(SpatieAuthorize::class))->newInstanceWithoutConstructor();
        return $middleware->handle($request, $next ?? fn () => new Response('passed'), 'web');
    }

    private function assertForbidden(\Closure $call): string
    {
        try {
            $call();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            return $exception->getMessage();
        }
        $this->fail('Expected 403');
    }
}
