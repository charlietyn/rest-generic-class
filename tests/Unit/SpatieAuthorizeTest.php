<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SpatieAuthorizeTest extends ChannelAuthorizationTestCase
{
    private function handle(?string $name, string $uri = 'admin/users'): Response
    {
        $request = Request::create('/' . $uri);
        $route = new Route('GET', $uri, fn () => 'ok');
        if ($name !== null) {
            $route->name($name);
        }
        $request->setRouteResolver(fn () => $route);
        // Registrar is an optional dependency and unused by handle().
        $middleware = (new \ReflectionClass(SpatieAuthorize::class))->newInstanceWithoutConstructor();
        return $middleware->handle($request, fn () => new Response('passed'), 'web');
    }

    public function testLegacyUnresolvedAllowPasses(): void
    {
        config(['rest-generic-class.permissions.authorization.on_unresolved' => 'allow']);
        $this->assertSame('passed', $this->handle(null)->getContent());
    }

    public function testUnresolvedDenyReturns403WithoutDebugDetails(): void
    {
        config(['app.debug' => false]);
        try {
            $this->handle(null);
            $this->fail('Expected 403');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('Forbidden', $exception->getMessage());
        }
    }

    public function testOutOfScopeProtectedRouteIsDenied(): void
    {
        $this->expectException(HttpException::class);
        $this->handle('administration.users.index', 'administration/users');
    }

    public function testGuestCannotAccessResolvedRoute(): void
    {
        $this->expectException(HttpException::class);
        $this->handle('admin.users.index');
    }

    public function testClientCannotUseAdminRouteAndCanUseGrantedClientRoute(): void
    {
        $user = new ChannelUser();
        $user->id = 1;
        $permission = $this->permission('client.users.index');
        $user->enabled_permissions()->attach($permission->id);
        $this->actingAs($user, 'web');
        $this->assertSame('passed', $this->handle('client.users.index', 'client/users')->getContent());
        try {
            $this->handle('admin.users.index');
            $this->fail('Expected 403');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
