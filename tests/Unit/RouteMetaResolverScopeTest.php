<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;

final class RouteMetaResolverScopeTest extends TestCase
{
    public function testLegacyScopeAndDerivedActions(): void
    {
        $resolver = new RouteMetaResolver();
        $route = $this->route('api', 'users.store', 'store');
        $this->assertSame('users.create', $resolver->resolveFromRoute($route, 'api')->canonicalName);
        $this->assertNull($resolver->resolveFromRoute($this->route('admin'), 'api'));
        $this->assertNull($resolver->resolveFromRoute($this->route('api', 'users.create'), 'api'));
        $this->assertNull($resolver->resolve(Request::create('/api/users'), 'api'));
    }

    public function testChannelScopeUsesUriBoundariesAndSnakeCaseModules(): void
    {
        $resolver = new RouteMetaResolver();
        $cfg = ['route_prefixes' => ['admin', 'client']];
        foreach (['admin', 'client'] as $channel) {
            $route = $this->route($channel . '/mod_security');
            $meta = $resolver->resolveFromRoute($route, 'api', ['ModSecurity'], $cfg);
            $this->assertSame('mod_security', $meta->module);
            $this->assertSame('mod_security.users.index', $meta->canonicalName);
        }
        foreach (['administration', 'other', 'administer'] as $prefix) {
            $this->assertNull($resolver->resolveFromRoute($this->route($prefix), 'api', [], $cfg));
        }
        // Explicit URI scope also works without a Laravel route group prefix.
        $route = new Route('GET', 'admin/users', ['controller' => 'UsersController@index']);
        $this->assertNotNull($resolver->resolveFromRoute($route, 'api', [], $cfg));
        $this->assertNull($resolver->resolveFromRoute($route, 'api', [], ['route_prefixes' => []]));
    }

    public function testRouteNamesRemainLiteralIncludingActionsAndClosures(): void
    {
        $resolver = new RouteMetaResolver();
        $cfg = ['route_prefixes' => ['admin'], 'naming' => 'route_name'];
        foreach (['store', 'destroy', 'create', 'edit'] as $action) {
            $name = 'admin.mod_security.users.' . $action;
            $meta = $resolver->resolveFromRoute($this->route('admin/mod_security', $name, $action), 'api', ['ModSecurity'], $cfg);
            $this->assertSame($name, $meta->canonicalName);
            $this->assertSame($action, $meta->action);
        }
        $route = new Route('GET', 'admin/auth/permissions', fn () => 'ok');
        $this->assertNull($resolver->resolveFromRoute($route, 'api', [], $cfg));
        $route->name('admin.auth.permissions');
        $this->assertSame('admin.auth.permissions', $resolver->resolveFromRoute($route, 'api', [], $cfg)->canonicalName);
    }

    public function testRequestAndCatalogAgreeOnVersionAndClosureHandling(): void
    {
        $resolver = new RouteMetaResolver();
        $route = $this->route('api', 'users.store', 'store');
        $route->setAction($route->getAction() + ['version' => 'v1.2']);
        $request = Request::create('/api/users', 'POST');
        $request->setRouteResolver(fn () => $route);
        $this->assertSame('v12.users.create', $resolver->resolve($request, 'api')->canonicalName);
        $this->assertEquals($resolver->resolveFromRoute($route, 'api'), $resolver->resolve($request, 'api'));
        $cfg = ['naming' => 'route_name'];
        $this->assertSame('users.store', $resolver->resolve($request, 'api', [], $cfg)->canonicalName);
        $route = (new Route('GET', 'api/users', fn () => 'ok'))->prefix('api');
        $request->setRouteResolver(fn () => $route);
        $this->assertNull($resolver->resolve($request, 'api'));
        $this->assertNull($resolver->resolveFromRoute($route, 'api'));
    }

    public function testScopeAndFilterHooksRemainOverridable(): void
    {
        $resolver = new class extends RouteMetaResolver {
            protected function matchedPrefix(Route $route, string $guard, array $cfg): ?string
            {
                return 'custom';
            }
            protected function passesFilters(string $uri, ?string $name, array $mw, array $cfg): bool
            {
                return $cfg['include'] ?? false;
            }
        };
        $route = $this->route('custom');
        $this->assertNull($resolver->resolveFromRoute($route, 'api'));
        $this->assertNotNull($resolver->resolveFromRoute($route, 'api', [], ['include' => true]));
    }

    private function route(string $prefix, string $name = 'users.index', string $method = 'index'): Route
    {
        return (new Route('GET', 'users', ['controller' => 'UsersController@' . $method]))->prefix($prefix)->name($name);
    }
}
