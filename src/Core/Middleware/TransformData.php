<?php

namespace Ronu\RestGenericClass\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * * TransformData Middleware
 * This middleware is responsible for transforming and validating incoming request data, for specific HTTP methods (PUT, PATCH, POST).
 * Scenarios where this middleware is applied typically involve data creation or modification.
 */

class TransformData
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $method = $request->method();
        //verify validate conditions
        //$checking = str_ends_with($request->getPathInfo(), '/update_multiple') || str_ends_with($request->getPathInfo(), '/validate') || ($method == 'POST' && substr_count($request->getPathInfo(), '/') == 2) || (($method == 'PUT' || $method == 'PATCH') && substr_count($request->getPathInfo(), '/') == 3);
        $checking = in_array($method, ['PUT', 'PATCH', 'POST'], true);
        if ($checking) {
            $class = array_slice(func_get_args(), 2)[0];
            $parameters=$request->route()->parameters;
            if (count($parameters)>0) {
                foreach ($parameters as $key => $value) {
                    $request->request->add([$key => $value]);
                }
            }
            $class::createFrom($request)->validate_request();
        }
        return $next($request);
    }
}

