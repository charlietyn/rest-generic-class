<?php

namespace Ronu\RestGenericClass\Core\Middleware;


use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: FilterRequestByRole
 *
 * First line of defense in the role-based field restriction pipeline.
 * Strips or rejects request fields that the authenticated user is not allowed
 * to write, based on $fieldsByRole declared on the target Eloquent model.
 *
 * ─── Position in the middleware pipeline ─────────────────────────────────────
 *
 *   ctx.resolve → ctx.auth → maybe.permission → role.filter → data.transform
 *                                                     ↑
 *                                               This middleware
 *
 * Must run AFTER authentication (ctx.auth) so that auth()->user() is available,
 * and BEFORE data.transform so the FormRequest never sees denied fields.
 *
 * ─── _model_class attribute injection ────────────────────────────────────────
 *
 * This middleware injects the resolved model class name as a request attribute:
 *
 *   $request->attributes->set('_model_class', $modelClass)
 *
 * This bridges FilterRequestByRole and BaseRequest without requiring any
 * changes to the vendor TransformData middleware. BaseRequest reads this
 * attribute in mergeProhibitedRules() to auto-resolve the model — so child
 * FormRequests do NOT need to declare $modelClass unless role.filter is
 * absent from the route.
 *
 * ─── Two modes ────────────────────────────────────────────────────────────────
 *
 *  silent (default)
 *      Denied fields are silently removed. The client receives no indication
 *      the field was ignored. Recommended for mobile/site channels.
 *
 *  strict
 *      If the client sent ANY denied field, abort(403) with the field names.
 *      Recommended for partner/B2B channels where clients know the contract.
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *   // Silent (default):
 *   'role.filter:App\Models\User'
 *
 *   // Strict:
 *   'role.filter:App\Models\User,strict'
 *
 * ─── When this middleware is a no-op ─────────────────────────────────────────
 *
 *  - HTTP verb is not POST / PUT / PATCH
 *  - No authenticated user (anonymous request)
 *  - Model class does not exist
 *  - Model does not implement getDeniedFieldsForUser()
 *  - getDeniedFieldsForUser() returns [] (no restrictions / superuser)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class FilterRequestByRole
{
    /**
     * HTTP methods that carry a writable payload and must be filtered.
     */
    private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string   $modelClass  Fully-qualified Eloquent model class name.
     * @param  string   $mode        'silent' (default) | 'strict'
     */
    public function handle(
        Request $request,
        Closure $next,
        string  $modelClass,
        string  $mode = 'silent'
    ): Response {

        // ── Only filter mutation requests ─────────────────────────────────────
        if (!in_array($request->method(), self::MUTATION_METHODS, true)) {
            return $next($request);
        }

        // ── Guard: model class must exist ─────────────────────────────────────
        if (!class_exists($modelClass)) {
            // Throw in non-production environments to surface misconfiguration.
            // In production, fail open (let the request through) to avoid
            // a middleware config error from blocking legitimate traffic.
            if (!app()->isProduction()) {
                throw new \RuntimeException(
                    "FilterRequestByRole: [{$modelClass}] does not exist. "
                    . "Check the middleware parameter in your route definition."
                );
            }

            return $next($request);
        }

        // ── Always inject _model_class so BaseRequest can resolve it ──────────
        // This happens regardless of whether the user has restrictions, so that
        // BaseRequest::mergeProhibitedRules() can always access the model class.
        $request->attributes->set('_model_class', $modelClass);

        // ── Require an authenticated user ─────────────────────────────────────
        $user = auth()->user();

        if ($user === null) {
            // Route allows anonymous access — nothing to filter.
            return $next($request);
        }

        // ── Resolve model and check for restriction support ───────────────────
        $model = new $modelClass();

        if (!method_exists($model, 'getDeniedFieldsForUser')) {
            return $next($request);
        }

        $denied = $model->getDeniedFieldsForUser($user);

        // No restrictions for this user (empty $fieldsByRole or superuser)
        if (empty($denied)) {
            return $next($request);
        }

        // ── Detect attempted writes on denied fields ──────────────────────────
        $attempted = array_intersect(array_keys($request->all()), $denied);

        if (!empty($attempted)) {

            if ($mode === 'strict') {
                // Hard rejection: tell the client exactly what it cannot modify
                abort(
                    Response::HTTP_FORBIDDEN,
                    'You are not allowed to modify: ' . implode(', ', array_values($attempted))
                );
            }

            // Silent mode: strip denied fields from all input bags
            // request->request = ParameterBag for form-data / JSON body
            // request->query    = ParameterBag for query string (edge case)
            foreach ($denied as $field) {
                $request->request->remove($field);
                $request->query->remove($field);
            }
        }

        return $next($request);
    }
}