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

    public function testDefaultNullConfigurationPreservesV250ModuleNames(): void
    {
        $resolver = new RouteMetaResolver();
        $cfg = ['route_prefixes' => null, 'global_permissions' => null, 'catalog_middleware' => null];
        foreach ([
            'api/mod_security' => ['--site--', 'users.index'],
            'api/ModSecurity' => ['ModSecurity', 'ModSecurity.users.index'],
            'api/mod_other' => ['--site--', 'users.index'],
            'apiv2/ModSecurity' => ['--site--', 'users.index'],
        ] as $prefix => [$module, $name]) {
            $route = $this->route($prefix);
            $meta = $resolver->resolveFromRoute($route, 'api', ['ModSecurity'], $cfg);
            $this->assertSame($module, $meta->module);
            $this->assertSame($name, $meta->canonicalName);
            $this->assertEquals($meta, $resolver->resolveFromRoute($route, 'api', ['ModSecurity']));
            $request = Request::create('/' . $route->uri());
            $request->setRouteResolver(fn () => $route);
            $this->assertEquals($meta, $resolver->resolve($request, 'api', ['ModSecurity'], $cfg));
        }
    }

    public function testLegacyModuleResolutionMatchesV250ForShortAndIrregularUris(): void
    {
        $resolver = new class extends RouteMetaResolver {
            public function module(string $uri, string $guard, array $modules): ?string
            {
                return $this->resolveModuleFromUri($uri, $guard, $modules);
            }
        };
        // These expectations come from the exact v2.5.0 segment/count checks.
        foreach ([
            ['api', 'api', null],
            ['api/ModSecurity', 'api', null],
            ['api/ModSecurity/', 'api', null],
            ['api/ModSecurity/users', 'api', 'ModSecurity'],
            ['/api//ModSecurity/users', 'api', 'ModSecurity'],
            ['api/mod_security/users', 'api', null],
            ['apiv2/ModSecurity/users', 'api', null],
            ['api/ModSecurity/users', 'api/', null],
            ['api/admin/ModSecurity/users', 'api/admin', null],
        ] as [$uri, $guard, $expected]) {
            $this->assertSame($expected, $resolver->module($uri, $guard, ['ModSecurity']), $uri);
        }
        $route = (new Route('GET', 'ModSecurity', ['controller' => 'UsersController@index']))->prefix('api');
        $this->assertSame('users.index', $resolver->resolveFromRoute($route, 'api', ['ModSecurity'])->canonicalName);
    }

    public function testNestedChannelPrefixesStillAcceptSnakeCaseModules(): void
    {
        $meta = (new RouteMetaResolver())->resolveFromRoute(
            $this->route('api/admin/mod_security'), 'api', ['ModSecurity'], ['route_prefixes' => ['api/admin']]
        );
        $this->assertSame('mod_security', $meta->module);
        $this->assertSame('mod_security.users.index', $meta->canonicalName);
    }

    public function testExistingThreeArgumentModuleOverridesWorkInBothModesWithoutLeakingState(): void
    {
        $resolver = new class extends RouteMetaResolver {
            public int $calls = 0;
            protected function resolveModuleFromUri(string $uri, string $guard, array $modules): ?string
            {
                $this->calls++;
                return parent::resolveModuleFromUri($uri, $guard, $modules);
            }
        };
        $channel = $resolver->resolveFromRoute($this->route('admin/mod_security'), 'api', ['ModSecurity'], ['route_prefixes' => ['admin']]);
        $legacy = $resolver->resolveFromRoute($this->route('api/mod_security'), 'api', ['ModSecurity']);
        $this->assertSame('mod_security', $channel->module);
        $this->assertSame('--site--', $legacy->module);
        $this->assertSame(2, $resolver->calls);
    }

    public function testModuleMatchingModeIsRestoredAfterAnOverrideThrows(): void
    {
        $resolver = new class extends RouteMetaResolver {
            public bool $shouldThrow = true;
            protected function resolveModuleFromUri(string $uri, string $guard, array $modules): ?string
            {
                if ($this->shouldThrow) {
                    throw new \RuntimeException('Application override failed');
                }
                return parent::resolveModuleFromUri($uri, $guard, $modules);
            }
            public function module(): ?string
            {
                return $this->resolveModuleFromUri('api/mod_security/users', 'api', ['ModSecurity']);
            }
        };
        try {
            $resolver->resolveFromRoute($this->route('admin/mod_security'), 'api', ['ModSecurity'], ['route_prefixes' => ['admin']]);
            $this->fail('The override should throw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Application override failed', $exception->getMessage());
        }
        $resolver->shouldThrow = false;
        $this->assertNull($resolver->module());
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
