<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Authorization\PermissionDecisionEvaluator;
use Ronu\RestGenericClass\Core\Support\Authorization\RequiredPermissionResolver;

final class AuthorizationSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'permission_map' => ['overrides' => ['legacy.route' => 'legacy.permission']],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('config');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('config');

        parent::tearDown();
    }

    // ---------------- RequiredPermissionResolver ----------------

    public function testExplicitOverrideWinsWithAllMode(): void
    {
        $resolver = new RequiredPermissionResolver();

        [$perms, $mode] = $resolver->resolve(
            Request::create('/articles', 'POST'),
            'articles.store',
            'store',
            ['defaults' => ['authorize' => [
                'permissions' => ['articles.create', 'articles.publish'],
                'mode' => 'all',
            ]]]
        );

        $this->assertSame(['articles.create', 'articles.publish'], $perms);
        $this->assertSame('all', $mode);
    }

    public function testOverrideDefaultsToAnyAndFallsThroughWhenEmpty(): void
    {
        $resolver = new RequiredPermissionResolver();

        // Empty permissions in override => fall through to route-name convention.
        [$perms, $mode] = $resolver->resolve(
            Request::create('/articles', 'GET'),
            'articles.show',
            'show',
            ['defaults' => ['authorize' => ['permissions' => []]]]
        );

        $this->assertSame(['articles.view'], $perms);
        $this->assertSame('any', $mode);
    }

    public function testRouteNameConventionMapsSuffixes(): void
    {
        $resolver = new RequiredPermissionResolver();

        $this->assertSame('articles.view', $resolver->mapRouteNameToPermission('articles.show'));
        $this->assertSame('articles.delete', $resolver->mapRouteNameToPermission('articles.destroy'));
        $this->assertSame('articles.index', $resolver->mapRouteNameToPermission('articles.index'));
        $this->assertSame('legacy.permission', $resolver->mapRouteNameToPermission('legacy.route'));
        $this->assertNull($resolver->mapRouteNameToPermission('unmapped.custom'));
    }

    public function testNormalizePermissionsAcceptsStringPipeArrayAndNull(): void
    {
        $resolver = new RequiredPermissionResolver();

        $this->assertSame(['a', 'b', 'c'], $resolver->normalizePermissions('a|b|c'));
        $this->assertSame(['a'], $resolver->normalizePermissions('a'));
        $this->assertSame(['x', 'y'], $resolver->normalizePermissions(['x', 'y']));
        $this->assertSame([], $resolver->normalizePermissions(null));
    }

    public function testActionConventionResolvesFromControllerMethod(): void
    {
        $resolver = new RequiredPermissionResolver();

        $request = Request::create('/articles', 'POST');
        $route = new Route(['POST'], '/articles', ['controller' => 'App\\Http\\Controllers\\ArticleController@store']);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        // No route name, no override => Controller@method convention.
        [$perms] = $resolver->resolve($request, null, 'store', []);

        $this->assertSame(['article.create'], $perms);
    }

    public function testHttpVerbConventionIsLastResort(): void
    {
        $resolver = new RequiredPermissionResolver();

        $this->assertSame(
            'article.create',
            $resolver->mapHttpVerbToPermission($this->routedRequest('POST', 'articles'))
        );
        $this->assertSame(
            'article.view',
            $resolver->mapHttpVerbToPermission($this->routedRequest('GET', 'articles'))
        );
    }

    // ---------------- PermissionDecisionEvaluator ----------------

    public function testDecideAnyRequiresAtLeastOne(): void
    {
        $evaluator = new PermissionDecisionEvaluator();
        $user = new AuthorizationFakeUser(['articles.view']);

        $this->assertTrue($evaluator->decide($user, ['articles.create', 'articles.view'], 'any'));
        $this->assertFalse($evaluator->decide($user, ['articles.create'], 'any'));
    }

    public function testDecideAllRequiresEvery(): void
    {
        $evaluator = new PermissionDecisionEvaluator();
        $user = new AuthorizationFakeUser(['articles.view', 'articles.create']);

        $this->assertTrue($evaluator->decide($user, ['articles.view', 'articles.create'], 'all'));
        $this->assertFalse($evaluator->decide($user, ['articles.view', 'articles.publish'], 'all'));
    }

    public function testUnauthenticatedUserAlwaysFails(): void
    {
        $evaluator = new PermissionDecisionEvaluator();

        $this->assertFalse($evaluator->decide(null, ['articles.view'], 'any'));
        $this->assertFalse($evaluator->decide(null, ['articles.view'], 'all'));
        // Deny-by-default: no permissions required under "any" still fails for guest.
        $this->assertFalse($evaluator->canAny(null, []));
    }

    private function routedRequest(string $method, string $uri): Request
    {
        $request = Request::create('/' . $uri, $method);
        $route = new Route([$method], $uri, []);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}

final class AuthorizationFakeUser
{
    /** @param string[] $granted */
    public function __construct(private array $granted)
    {
    }

    public function can($permission): bool
    {
        return in_array($permission, $this->granted, true);
    }
}
