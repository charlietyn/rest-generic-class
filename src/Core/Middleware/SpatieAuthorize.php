<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;
use Ronu\RestGenericClass\Core\Support\Authorization\PermissionDecisionEvaluator;
use Ronu\RestGenericClass\Core\Support\Authorization\RequiredPermissionResolver;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the same actions as the route catalog and checks them with user->can(): one of the route's
 * abilities (permission_ability alternatives, canAny) and every permission_requires action (canAll).
 * On success the authorized route context is stored in the CONTEXT_ATTRIBUTE request attribute.
 * Configure permissions.authorization for channel prefixes and fail-closed behavior.
 * Authenticate first; the middleware parameter selects the authentication guard.
 * Protected compatibility helpers below are available to subclasses, but handle()
 * uses RouteMetaResolver rather than RequiredPermissionResolver.
 */
class SpatieAuthorize
{
    /** Request attribute with the authorized route context, read by eager relation authorization. */
    public const CONTEXT_ATTRIBUTE = 'rest-generic-class.authorization';

    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function handle(Request $request, Closure $next, ?string $guardParam = null): Response
    {
        $guard = $guardParam ?: (string) config('auth.defaults.guard');

        $modules = class_exists(Module::class) ? Module::toCollection()->map->getName()->values()->all() : [];
        $cfg = config('rest-generic-class.permissions.authorization', []);
        $resolver = app(RouteMetaResolver::class);
        $meta = $resolver->resolve($request, $guard, $modules, $cfg);

        if (!$meta) {
            if (($cfg['on_unresolved'] ?? 'allow') === 'deny') {
                abort(403, config('app.debug') ? 'Forbidden: route has no resolvable permission' : 'Forbidden');
            }
            return $next($request);
        }
        $user = auth($guard)->user();
        $evaluator = app(PermissionDecisionEvaluator::class);
        if (!$evaluator->canAny($user, $meta->abilities)) {
            abort(403, config('app.debug') ? 'Forbidden: ' . $this->describeMissingAbilities($meta->abilities) : 'Forbidden');
        }
        if (!$evaluator->canAll($user, $meta->requires)) {
            $missing = array_values(array_filter($meta->requires, static fn (string $action) => !$user->can($action)));
            abort(403, config('app.debug') ? 'Forbidden: missing ' . implode(', ', $missing) : 'Forbidden');
        }
        $request->attributes->set(self::CONTEXT_ATTRIBUTE, [
            'guard' => $guard,
            'action' => $meta->canonicalName,
            'resource' => str_contains($meta->canonicalName, '.') ? Str::beforeLast($meta->canonicalName, '.') : null,
        ]);
        return $next($request);
    }

    /** One alternative keeps the v2.7 message ("Forbidden: admin.users.index"). */
    private function describeMissingAbilities(array $abilities): string
    {
        return count($abilities) === 1 ? $abilities[0] : 'missing one of ' . implode(', ', $abilities);
    }

    /**
     * Resolve the guard using route overrides, middleware param, or app default.
     */
    protected function resolveGuard(array $actionArr, ?string $guardParam): string
    {
        $override = Arr::get($actionArr, 'defaults.authorize.guard');
        return $override
            ?: ($guardParam ?: (string) config('auth.defaults.guard'));
    }

    /**
     * Derive required permissions and evaluation mode ("any"|"all").
     *
     * Thin facade over {@see RequiredPermissionResolver}; kept for backward
     * compatibility with any subclass that relied on this seam.
     *
     * @return array{0: string[], 1: 'any'|'all'}
     */
    protected function resolveRequiredPermissions(
        Request $request,
        ?string $routeName,
        ?string $actionMethod,
        array $actionArr
    ): array {
        return app(RequiredPermissionResolver::class)
            ->resolve($request, $routeName, $actionMethod, $actionArr);
    }

    /**
     * Normalize permissions to an array of strings.
     * Accepts: string "a|b|c", string "a", array ['a','b'], null.
     */
    protected function normalizePermissions(null|string|array $perms): array
    {
        return app(RequiredPermissionResolver::class)->normalizePermissions($perms);
    }

    /**
     * Map from route name suffix to permission name.
     */
    protected function mapRouteNameToPermission(?string $name): ?string
    {
        return app(RequiredPermissionResolver::class)->mapRouteNameToPermission($name);
    }

    /**
     * Map from Controller@method to permission.
     */
    protected function mapActionToPermission(Request $request): ?string
    {
        return app(RequiredPermissionResolver::class)->mapActionToPermission($request);
    }

    /**
     * Last-resort mapping: HTTP verb + first URI segment.
     */
    protected function mapHttpVerbToPermission(Request $request): ?string
    {
        return app(RequiredPermissionResolver::class)->mapHttpVerbToPermission($request);
    }

    /**
     * Evaluate "ANY": user must hold at least one permission.
     */
    protected function userCanAny($user, array $permissions): bool
    {
        return app(PermissionDecisionEvaluator::class)->canAny($user, $permissions);
    }

    /**
     * Evaluate "ALL": user must hold all permissions.
     */
    protected function userCanAll($user, array $permissions): bool
    {
        return app(PermissionDecisionEvaluator::class)->canAll($user, $permissions);
    }
}
