<?php

namespace Ronu\RestGenericClass\Core\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Helper for extracting and manipulating parameters from a Request body.
 * Supports JSON, form-data, multipart, and raw body.
 *
 * @example RequestBody::get($request) // All parameters
 * @example RequestBody::get($request, 'user.email') // Single parameter with dot notation
 * @example RequestBody::get($request, ['name', 'email']) // Multiple parameters
 */
final class RequestBody
{
    /**
     * Extract parameters from body (without query params or route params).
     *
     * @param Request $request
     * @param string|array|null $keys null=all, string=single, array=multiple
     * @param array $options Configuration options
     * @return mixed
     */
    public static function get(Request $request, string|array|null $keys = null, array $options = []): mixed
    {
        $config = self::mergeOptions($options);
        $body = self::extractRawBody($request);

        // Apply global transformations
        $body = self::applyGlobalTransformations($body, $config);

        // If no keys specified, return everything
        if ($keys === null) {
            return self::applyTypeCasting($body, $config['casts']);
        }

        // If requesting a single key (string)
        if (is_string($keys)) {
            return self::getSingleValue($body, $keys, $config);
        }

        // If requesting multiple keys (array)
        return self::getMultipleValues($body, $keys, $config);
    }

    /**
     * More explicit alias to get all parameters.
     */
    public static function all(Request $request, array $options = []): array
    {
        return self::get($request, null, $options);
    }

    /**
     * Alias to get a single parameter with default value.
     */
    public static function only(Request $request, string $key, mixed $default = null, array $options = []): mixed
    {
        return self::get($request, $key, array_merge($options, ['default' => $default]));
    }

    /**
     * Get multiple specific parameters.
     */
    public static function pick(Request $request, array $keys, array $options = []): array
    {
        return self::get($request, $keys, $options);
    }

    /**
     * Validate that required keys exist (throws exception if missing).
     */
    public static function require(Request $request, array $requiredKeys, array $options = []): array
    {
        $body = self::get($request, null, $options);
        $missing = [];

        foreach ($requiredKeys as $key) {
            if (!Arr::has($body, $key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Missing required body parameters: ' . implode(', ', $missing)
            );
        }

        return Arr::only($body, $requiredKeys);
    }

    /**
     * Extract the raw body from the request.
     */
    private static function extractRawBody(Request $request): array
    {
        // 1. JSON Content-Type
        if (self::isJsonRequest($request)) {
            return self::extractJsonBody($request);
        }

        // 2. Form data (application/x-www-form-urlencoded or multipart/form-data)
        $formData = $request->request->all();
        if (!empty($formData)) {
            return is_array($formData) ? $formData : [];
        }

        // 3. Fallback: try to parse raw content
        return self::parseRawContent($request->getContent());
    }

    /**
     * Determine if the request is JSON.
     */
    private static function isJsonRequest(Request $request): bool
    {
        if ($request->isJson()) {
            return true;
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        return str_contains($contentType, 'application/json') || str_contains($contentType, '+json');
    }

    /**
     * Extract JSON body.
     */
    private static function extractJsonBody(Request $request): array
    {
        $json = $request->json()->all();

        // If empty, try to decode raw content
        if (empty($json)) {
            $raw = trim($request->getContent());
            if ($raw !== '' && self::looksLikeJson($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Parse raw content (JSON or URL-encoded).
     */
    private static function parseRawContent(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        // Try JSON
        if (self::looksLikeJson($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try URL-encoded
        parse_str($content, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Check if a string looks like JSON.
     */
    private static function looksLikeJson(string $str): bool
    {
        $str = ltrim($str);
        return $str !== '' && (str_starts_with($str, '{') || str_starts_with($str, '['));
    }

    /**
     * Merge options with defaults.
     */
    private static function mergeOptions(array $options): array
    {
        return array_merge([
            'default'        => null,
            'only'           => null,
            'except'         => [],
            'trim_strings'   => true,
            'empty_to_null'  => false,
            'include_files'  => false,
            'files_key'      => '__files',
            'drop_internal'  => true,
            'casts'          => [],
            'strict'         => false, // If true, throws exception on missing keys
        ], $options);
    }

    /**
     * Apply global transformations to the body.
     */
    private static function applyGlobalTransformations(array $body, array $config): array
    {
        // Remove Laravel internal fields
        if ($config['drop_internal']) {
            $body = Arr::except($body, ['_token', '_method']);
        }

        // Filter only specific keys
        if (is_array($config['only']) && !empty($config['only'])) {
            $body = self::extractDotKeys($body, $config['only']);
        }

        // Exclude specific keys
        if (is_array($config['except']) && !empty($config['except'])) {
            foreach ($config['except'] as $key) {
                Arr::forget($body, $key);
            }
        }

        // Normalize strings
        if ($config['trim_strings'] || $config['empty_to_null']) {
            $body = self::normalizeValues($body, $config['trim_strings'], $config['empty_to_null']);
        }

        return $body;
    }

    /**
     * Get a single value with options.
     */
    private static function getSingleValue(array $body, string $key, array $config): mixed
    {
        $exists = Arr::has($body, $key);

        if (!$exists && $config['strict']) {
            throw new \InvalidArgumentException("Required body parameter '$key' not found");
        }

        $value = data_get($body, $key, $config['default']);
        $caster = $config['casts'][$key] ?? null;

        return self::castSingleValue($value, $caster);
    }

    /**
     * Get multiple values.
     */
    private static function getMultipleValues(array $body, array $keys, array $config): array
    {
        if ($config['strict']) {
            $missing = array_filter($keys, fn($k) => !Arr::has($body, $k));
            if (!empty($missing)) {
                throw new \InvalidArgumentException(
                    'Required body parameters not found: ' . implode(', ', $missing)
                );
            }
        }

        $subset = self::extractDotKeys($body, $keys);
        return self::applyTypeCasting($subset, $config['casts']);
    }

    /**
     * Extract only specified keys (supports dot notation).
     */
    private static function extractDotKeys(array $data, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (Arr::has($data, $key)) {
                Arr::set($result, $key, data_get($data, $key));
            }
        }

        return $result;
    }

    /**
     * Normalize values recursively.
     */
    private static function normalizeValues(mixed $value, bool $trim, bool $emptyToNull): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn($v) => self::normalizeValues($v, $trim, $emptyToNull),
                $value
            );
        }

        if (is_string($value)) {
            if ($trim) {
                $value = trim($value);
            }
            if ($emptyToNull && $value === '') {
                return null;
            }
        }

        return $value;
    }

    /**
     * Apply casts to all specified keys.
     */
    private static function applyTypeCasting(array $data, array $casts): array
    {
        foreach ($casts as $key => $caster) {
            if (Arr::has($data, $key)) {
                $value = data_get($data, $key);
                Arr::set($data, $key, self::castSingleValue($value, $caster));
            }
        }

        return $data;
    }

    /**
     * Cast a value according to the specified type.
     */
    private static function castSingleValue(mixed $value, string|callable|null $caster): mixed
    {
        if ($caster === null) {
            return $value;
        }

        // If it's a callable, execute it
        if (is_callable($caster)) {
            return $caster($value);
        }

        $caster = strtolower(trim($caster));

        // Handle dates (date or date:format)
        if (str_starts_with($caster, 'date')) {
            return self::castToDate($value, $caster);
        }

        // Basic casts
        return match ($caster) {
            'int', 'integer' => self::toInt($value),
            'float', 'double', 'decimal' => self::toFloat($value),
            'bool', 'boolean' => self::toBool($value),
            'string' => self::toString($value),
            'array' => self::toArray($value),
            'json' => self::toJson($value),
            'null' => null,
            default => $value,
        };
    }

    /**
     * Cast to Carbon date.
     */
    private static function castToDate(mixed $value, string $caster): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Extract format if exists (date:Y-m-d)
            if (str_contains($caster, ':')) {
                $format = explode(':', $caster, 2)[1];
                return Carbon::createFromFormat($format, (string) $value);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            // On error, return original value
            return $value;
        }
    }

    /**
     * Safe casting helpers.
     */
    private static function toInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function toFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function toString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return $value === null ? [] : (array) $value;
    }

    private static function toJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}