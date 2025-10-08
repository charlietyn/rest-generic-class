<?php

namespace Ronu\RestGenericClass\Core\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * InjectRequestParams
 *
 * Usage in routes:
 *   ->middleware('inject:scenario=assign,mode=REVOKE,dry_run=true')
 *
 * Options (as leading params):
 *   - ns=_meta     → prefix all keys with "_meta."
 *   - force        → overwrite existing request values
 *
 * Value casting:
 *   - "true"/"false" → boolean
 *   - "null"         → null
 *   - numeric        → int/float
 *   - "json:{...}" or "json:[...]" → JSON decoded
 *   - "b64:..."      → base64 decoded (then JSON-decoding if payload looks like JSON)
 */
class InjectRequestParams
{
    public function handle(Request $request, Closure $next, string ...$pairs)
    {
        $force = false;
        $ns    = ''; // e.g. "_meta." to prefix keys

        // Parse leading options (ns=..., force)
        $i = 0;
        while ($i < count($pairs)) {
            $token = trim($pairs[$i]);
            if ($token === '') { $i++; continue; }

            if (str_starts_with($token, 'ns=')) {
                $ns = rtrim(substr($token, 3), '.').'.';
                $i++;
                continue;
            }
            if ($token === 'force') {
                $force = true;
                $i++;
                continue;
            }

            // first non-option => stop options parsing
            break;
        }

        $injected = [];

        for (; $i < count($pairs); $i++) {
            $raw = trim($pairs[$i]);
            if ($raw === '') continue;

            // split by first '=' or ':'
            $posEq = strpos($raw, '=');
            $posCl = strpos($raw, ':');

            $pos = match (true) {
                $posEq !== false && $posCl !== false => min($posEq, $posCl),
                $posEq !== false => $posEq,
                $posCl !== false => $posCl,
                default => false,
            };

            if ($pos === false) {
                // lone flag like "force" (already handled) => skip
                continue;
            }

            $key   = trim(substr($raw, 0, $pos));
            $value = trim(substr($raw, $pos + 1));

            if ($key === '') continue;

            $casted = $this->castValue($value);

            $finalKey = $ns.$key;

            if ($force) {
                // force merge (overwrite)
                $request->merge([$finalKey => $casted]);
                $injected[$finalKey] = $casted;
            } else {
                // only set if not already present
                if (!$request->has($finalKey)) {
                    $request->merge([$finalKey => $casted]);
                    $injected[$finalKey] = $casted;
                }
            }
        }

        // Optionally: make injected values available as request attributes (not only input)
        foreach ($injected as $k => $v) {
            $request->attributes->set($k, $v);
        }

        return $next($request);
    }

    /**
     * Cast string to appropriate PHP type.
     * Supports: bool, null, number, json:{...}, b64:..., and raw strings.
     */
    private function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        // booleans
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;

        // null
        if ($lower === 'null')  return null;

        // json:{...} or json:[...]
        if (str_starts_with($lower, 'json:')) {
            $json = substr($value, 5);
            $decoded = json_decode($json, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        // b64:xxxx  (if decoded looks like JSON, decode it)
        if (str_starts_with($lower, 'b64:')) {
            $b64 = substr($value, 4);
            $decodedRaw = base64_decode($b64, true);
            if ($decodedRaw === false) return $value;

            $trim = ltrim($decodedRaw);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decodedJson = json_decode($decodedRaw, true);
                return json_last_error() === JSON_ERROR_NONE ? $decodedJson : $decodedRaw;
            }
            return $decodedRaw;
        }

        // numeric (int/float)
        if (is_numeric($value)) {
            return $value + 0; // cast to int/float
        }

        return $value; // fallback raw string
    }
}
