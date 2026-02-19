<?php

namespace Ronu\RestGenericClass\Core\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TransformData Middleware
 *
 * Transforms and validates incoming request data for mutation operations.
 * Injects route parameters into the request before validation.
 *
 * Usage:
 *   - $this->middleware('data.transform:App\Http\Requests\YourRequest');
 *   - $this->middleware('data.transform:...')->only(['store', 'update', 'destroy']);
 */
class TransformData
{
    /**
     * HTTP methods that require validation.
     */
    private const VALIDATABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $formRequestClass The FormRequest class to use for validation
     * @return Response
     *
     * @throws \InvalidArgumentException If FormRequest class is missing or invalid
     * @throws ValidationException If validation fails
     */
    public function handle(
        Request $request,
        Closure $next,
        ?string $formRequestClass = null
    ): Response {
        $method = $request->method();

        // Early return for non-validatable methods
        if (!in_array($method, self::VALIDATABLE_METHODS, true)) {
            return $next($request);
        }

        // Validate middleware configuration
        $this->validateConfiguration($formRequestClass);

        // Inject route parameters into request
        $this->injectRouteParameters($request);

        // Perform validation
        $this->performValidation($request, $formRequestClass);

        return $next($request);
    }

    /**
     * Validate that the middleware is properly configured.
     *
     * @throws \InvalidArgumentException
     */
    private function validateConfiguration(?string $formRequestClass): void
    {
        if ($formRequestClass === null || $formRequestClass === '') {
            throw new \InvalidArgumentException(
                'TransformData middleware requires a FormRequest class as parameter. ' .
                'Usage: middleware("data.transform:{PathToYour\\FormRequestClass}")'
            );
        }

        if (!class_exists($formRequestClass)) {
            throw new \InvalidArgumentException(
                "FormRequest class [{$formRequestClass}] does not exist"
            );
        }
    }

    /**
     * Inject route parameters into the request.
     * Handles Route Model Binding by extracting the primary key.
     */
    private function injectRouteParameters(Request $request): void
    {
        $route = $request->route();

        if ($route === null) {
            return;
        }

        $parameters = $route->parameters();

        if (empty($parameters)) {
            return;
        }

        $normalized = [];

        foreach ($parameters as $key => $value) {
            if ($value instanceof Model) {
                // Route Model Binding: extract the key
                $normalized[$key] = $value->getKey();
            } else {
                $normalized[$key] = $value;
            }
        }

        // merge() overwrites existing keys (safer than add())
        $request->merge($normalized);
    }

    /**
     * Perform validation using the FormRequest.
     *
     * @throws ValidationException
     * @throws \RuntimeException
     */
    private function performValidation(Request $request, string $formRequestClass): void
    {
        try {
            /** @var \Illuminate\Foundation\Http\FormRequest $formRequestClass */
            $formRequest = $formRequestClass::createFrom($request);

            if (!method_exists($formRequest, 'validate_request')) {
                throw new \BadMethodCallException(
                    "Class [{$formRequestClass}] must implement validate_request() method"
                );
            }

            $formRequest->validate_request();

        } catch (ValidationException $e) {
            throw $e;
        } catch (\BadMethodCallException $e) {
            throw $e;
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logger()->error('TransformData: Unexpected validation error', [
                'form_request' => $formRequestClass,
                'method' => $request->method(),
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Validation failed unexpectedly: {$e->getMessage()}",
                500,
                $e
            );
        }
    }
}