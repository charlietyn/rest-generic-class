<?php

namespace Ronu\RestGenericClass\Core\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Helper for extracting and manipulating parameters from a Request body.
 * Supports JSON, form-data, multipart, raw body, and works with ANY HTTP method.
 *
 * @example RequestBody::get($request) // All parameters
 * @example RequestBody::get($request, 'user.email') // Single parameter with dot notation
 * @example RequestBody::get($request, ['name', 'email']) // Multiple parameters
 */
final class RequestBody
{
    /**
     * Extract parameters from body (without query params or route params).
     * Works with GET, POST, PUT, PATCH, DELETE, and any HTTP method.
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
     * Handles ALL scenarios: JSON, form-data, multipart, raw content.
     * Works with ANY HTTP method including GET.
     */
    private static function extractRawBody(Request $request): array
    {
        // Strategy 1: Try raw content first (most reliable and flexible)
        $rawContent = $request->getContent();

        if (!empty($rawContent)) {
            $parsed = self::parseRawContent($rawContent, $request);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        // Strategy 2: Laravel's request ParameterBag (handles form-data and multipart)
        // This is populated for POST/PUT/PATCH with form-urlencoded or multipart
        $bodyParams = $request->request->all();
        if (!empty($bodyParams)) {
            return is_array($bodyParams) ? $bodyParams : [];
        }

        // Strategy 3: Try Laravel's JSON helper (should have been caught by Strategy 1)
        if (self::isJsonRequest($request)) {
            $json = self::extractJsonBody($request);
            if (!empty($json)) {
                return $json;
            }
        }

        // Strategy 4: Check for input() data (Laravel merges sources)
        // Only use this as last resort and exclude query params
        $allInput = $request->input();
        $queryParams = $request->query();

        if (!empty($allInput) && is_array($allInput)) {
            // Remove query params from the mix
            $bodyOnly = array_diff_key($allInput, $queryParams);
            if (!empty($bodyOnly)) {
                return $bodyOnly;
            }
        }

        return [];
    }

    /**
     * Determine if the request is JSON based on Content-Type header.
     */
    private static function isJsonRequest(Request $request): bool
    {
        if ($request->isJson()) {
            return true;
        }

        $contentType = strtolower((string)$request->header('Content-Type', ''));
        return str_contains($contentType, 'application/json')
            || str_contains($contentType, '+json')
            || str_contains($contentType, 'text/json');
    }

    /**
     * Extract JSON body using Laravel's JSON helper (fallback method).
     */
    private static function extractJsonBody(Request $request): array
    {
        try {
            $json = $request->json()->all();
            return is_array($json) && !empty($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parse raw content - handles JSON, URL-encoded, and malformed content.
     * This is the CORE parsing method that handles all edge cases.
     */
    private static function parseRawContent(string $content, Request $request): array
    {
        // Clean the content (remove BOM, trim whitespace)
        $content = self::cleanRawContent($content);

        if ($content === '') {
            return [];
        }

        // Attempt 1: Parse as JSON (most common for APIs)
        if (self::looksLikeJson($content)) {
            $decoded = self::parseJson($content);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // Attempt 2: Parse as URL-encoded (form-data)
        // Example: key1=value1&key2=value2
        $urlEncoded = self::parseUrlEncoded($content);
        if (!empty($urlEncoded)) {
            return $urlEncoded;
        }

        // Attempt 3: Try to fix and parse malformed JSON
        $fixedJson = self::fixMalformedJson($content);
        if ($fixedJson !== null) {
            return $fixedJson;
        }

        // Attempt 4: Check if it's a simple key=value format
        $simpleKeyValue = self::parseSimpleKeyValue($content);
        if (!empty($simpleKeyValue)) {
            return $simpleKeyValue;
        }

        return [];
    }

    /**
     * Clean raw content from BOM, extra whitespace, and invisible characters.
     */
    private static function cleanRawContent(string $content): string
    {
        // Remove UTF-8 BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Trim whitespace
        return trim($content);
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
     * Parse JSON with proper error handling.
     * Handles whitespace, newlines, and tabs in JSON.
     */
    private static function parseJson(string $json): ?array
    {
        // First attempt: direct decode (handles most cases including \n and \t)
        $decoded = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Second attempt: normalize whitespace and try again
        $normalized = preg_replace('/\s+/', ' ', $json);
        $decoded = json_decode($normalized, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Fix common malformed JSON issues and attempt to parse.
     * Handles: missing quotes, trailing commas, unescaped characters, etc.
     */
    private static function fixMalformedJson(string $content): ?array
    {
        $original = $content;

        // Remove literal \n and \t if they appear as text (not as actual newlines)
        $content = str_replace(['\n', '\t', '\r'], ['', '', ''], $content);

        // Try to fix unquoted keys/values
        // Match patterns like: { key: value } and convert to: { "key": "value" }
        $content = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) {
                $inner = $matches[1];

                // Split by comma
                $pairs = preg_split('/,\s*/', trim($inner));
                $fixed = [];

                foreach ($pairs as $pair) {
                    // Match key: value or "key": value or key: "value"
                    if (preg_match('/^\s*(["\']?)(\w+)\1\s*:\s*(["\']?)(.+?)\3\s*$/', $pair, $m)) {
                        $key = $m[2];
                        $value = $m[4];

                        // Ensure both key and value are quoted
                        $fixed[] = sprintf('"%s": "%s"', $key, $value);
                    }
                }

                return '{' . implode(', ', $fixed) . '}';
            },
            $content
        );

        // Remove trailing commas
        $content = preg_replace('/,\s*([}\]])/', '$1', $content);

        // Try to decode
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Parse URL-encoded content (application/x-www-form-urlencoded).
     */
    private static function parseUrlEncoded(string $content): array
    {
        // Check if it looks like URL-encoded data
        if (!str_contains($content, '=')) {
            return [];
        }

        $parsed = [];
        parse_str($content, $parsed);

        return is_array($parsed) && !empty($parsed) ? $parsed : [];
    }

    /**
     * Parse simple key=value format (without &).
     * Example: "api_key=123456" or "token=abc"
     */
    private static function parseSimpleKeyValue(string $content): array
    {
        // Match pattern: key=value (single pair, no &)
        if (preg_match('/^(\w+)=(.+)$/', trim($content), $matches)) {
            return [$matches[1] => $matches[2]];
        }

        return [];
    }

    /**
     * Merge options with defaults.
     */
    private static function mergeOptions(array $options): array
    {
        return array_merge([
            'default' => null,
            'only' => null,
            'except' => [],
            'trim_strings' => true,
            'empty_to_null' => false,
            'include_files' => false,
            'files_key' => '__files',
            'drop_internal' => true,
            'casts' => [],
            'strict' => false,
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
                return Carbon::createFromFormat($format, (string)$value);
            }

            return Carbon::parse((string)$value);
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
        return ($value === null || $value === '') ? null : (int)$value;
    }

    private static function toFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float)$value;
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
        return $value === null ? null : (string)$value;
    }

    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return $value === null ? [] : (array)$value;
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