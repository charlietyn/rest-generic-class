<?php
/**
 * Created by PhpStorm.
 * User: Charlietyn
 * Date: 2022-08-25
 * Time: 12:37 AM
 */

namespace Ronu\RestGenericClass\Core\Helpers;

use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Laravel\Prompts\Output\ConsoleOutput;
use Symfony\Component\Console\Input\ArgvInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

class Helper
{
    /**
     * Returns the day difference between $current_date and $date (YYYY-mm-dd...).
     */
    public static function diff($current_date, $date)
    {
        return intval($current_date->diff(new \DateTime($date))->format('%R%a'));
    }

    /**
     * Generate a quick random alphanumeric string.
     */
    public static function quickRandom($length = 16)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
    }

    /**
     * Generate an API key-like signature using HMAC SHA256 + base64.
     */
    public static function generateApikey($secretKey)
    {
        $salt = mt_rand();
        $signature = hash_hmac('sha256', $salt, $secretKey, true);
        $encodedSignature = base64_encode($signature);
        return $encodedSignature;
    }

    /**
     * Generate a random numeric string.
     */
    public static function generateRamdomNumber($length = 6)
    {
        return substr(str_shuffle("0123456789"), 0, $length);
    }

    /**
     * Convert a JPG/PNG image to WEBP (best-effort).
     *
     * @param string $filepath Absolute path to image.
     * @param int $quantity WEBP quality (0-100).
     * @param string $ext Original extension ('jpg' | 'png').
     * @return string           Resulting WEBP file path.
     */
    public function convert_to_webp($filepath, $quantity, $ext = 'jpg')
    {
        $image_extension = substr(basename($filepath), strrpos(basename($filepath), '.'), strlen($filepath));
        try {
            $imag = $ext == 'jpg' ? imagecreatefromjpeg($filepath) : imagecreatefrompng($filepath);
            $webp_file = str_replace($image_extension, ".webp", $filepath);
            $w = imagesx($imag);
            $h = imagesy($imag);
            $webp = imagecreatetruecolor($w, $h);
            imagecopy($webp, $imag, 0, 0, 0, 0, $w, $h);
            imagewebp($webp, $webp_file, $quantity);
            imagedestroy($imag);
            imagedestroy($webp);
        } catch (\Exception $e) {
            // Fallback: if conversion fails, just change the extension string.
            $webp_file = str_replace($image_extension, "webp", $filepath);
        }
        return $webp_file;
    }

    /**
     * Load data from a JSON file and UPSERT rows one-by-one using a primary/unique key.
     *
     * IMPORTANT for seeder:
     *  - For each row: first check if the key ($pk) exists in the DB.
     *      - If it exists -> UPDATE (exclude pk from payload).
     *      - If it does not exist -> INSERT.
     *  - This explicitly follows the "check-then-update-else-insert" flow you requested.
     *
     * Metrics are returned to build a consolidated report in DatabaseSeeder.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass Eloquent model class.
     * @param string $jsonPath Absolute/relative path to a JSON file with an array of rows (or a single object).
     * @param string $pk Primary/unique key column (default 'id').
     * @param int $chunkSize How many rows to process per chunk inside the transaction.
     * @return array{
     *   model:string,table:string,file:string,
     *   inserted:int,updated:int,skipped:int,
     *   errors:array<int,array{key:mixed,message:string}>,
     *   duration_ms:int
     * }
     */
    public static function loadFromJson(string $modelClass, string $jsonPath, string $pk = 'id', int $chunkSize = 1000): array
    {
        $started = microtime(true);
        $model = new $modelClass;
        $table = $model->getTable();
        $conn = $model->getConnectionName();

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Validate file presence
        if (!is_file($jsonPath)) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'file' => $jsonPath,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [['key' => null, 'message' => "File not found: {$jsonPath}"]],
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        }

        // Read + decode JSON
        $raw = file_get_contents($jsonPath);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'file' => $jsonPath,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [['key' => null, 'message' => "Invalid JSON in {$jsonPath}"]],
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        }

        // Normalize: allow a single object as input
        if (Arr::isAssoc($data)) {
            $data = [$data];
        }

        // Filter rows without PK and non-array items
        $rows = [];
        foreach ($data as $idx => $row) {
            if (!is_array($row)) {
                $skipped++;
                $errors[] = ['key' => null, 'message' => "Row {$idx} is not an object/array."];
                continue;
            }
            if (!array_key_exists($pk, $row)) {
                $skipped++;
                $errors[] = ['key' => null, 'message' => "Row {$idx} missing primary/unique key '{$pk}'."];
                continue;
            }
            foreach ($row as $key => $value) {
                if (is_string($value) && $format = self::detectDateFormat($value)) {
                    if (Carbon::hasFormat($value, $format)) {
                        $row[$key] = Carbon::createFromFormat($format, $value)->format('Y-m-d H:i:s');
                    }
                }
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'file' => $jsonPath,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => $skipped,
                'errors' => $errors,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        }

        // Determine columns to update (all except the PK)
        $allColumns = array_keys(array_reduce($rows, function ($carry, $r) {
            foreach ($r as $k => $v) $carry[$k] = true;
            return $carry;
        }, []));
        $updateColumns = array_values(array_diff($allColumns, [$pk]));

        // Process inside a transaction, but keep per-row try/catch to continue on errors.
        try {
            DB::connection($conn)->transaction(function () use (
                $conn, $table, $rows, $pk, $updateColumns, $chunkSize, &$inserted, &$updated, &$errors
            ) {
                foreach (array_chunk($rows, $chunkSize) as $chunk) {
                    foreach ($chunk as $idx => $row) {
                        $keyValue = $row[$pk];

                        // 1) Check existence
                        $exists = DB::connection($conn)
                            ->table($table)
                            ->where($pk, $keyValue)
                            ->exists();

                        if ($exists) {
                            // 2) UPDATE existing row (exclude the PK from payload)
                            $payload = Arr::only($row, $updateColumns);
                            if (!empty($payload)) {
                                DB::connection($conn)
                                    ->table($table)
                                    ->where($pk, $keyValue)
                                    ->update($payload);
                            }
                            $updated++;
                        } else {
                            // 3) INSERT new row (full payload, including PK if present)
                            DB::connection($conn)
                                ->table($table)
                                ->insert($row);
                            $inserted++;
                        }
                    }
                }
            }, 3);
        } catch (\Throwable $e) {
            // Keep going; record the error for the final report
            $errors[] = [
                'key' => $table,
                'message' => $e->getMessage(),
            ];
        }
        return [
            'model' => $modelClass,
            'table' => $table,
            'file' => $jsonPath,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Try to infer the date format of a given string.
     * Returns the matching PHP date() format or null if none.
     */
    public static function detectDateFormat(string $value): ?string
    {
        $formats = [
            // Common full datetime formats
            'Y-m-d H:i:s', 'd/m/Y H:i:s', 'd-m-Y H:i:s',
            'j/n/Y H:i:s', 'j-n-Y H:i:s', 'Y/m/d H:i:s', 'Y.n.j H:i:s',
            // Date-only formats
            'Y-m-d', 'd/m/Y', 'd-m-Y', 'j/n/Y', 'j-n-Y', 'Y/m/d', 'Y.n.j',
            // Time-only
            'H:i:s', 'H:i',
            // AM/PM
            'Y-m-d h:i:s A', 'd/m/Y h:i A',
            // ISO 8601
            'c', 'Y-m-d\TH:i:sP',
            // RFC 2822
            'D, d M Y H:i:s O',
        ];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
                return $format;
            }
        }
        return null;
    }

    /**
     * Heuristic check: does a string look like a date?
     */
    public static function looksLikeDateString(string $value): bool
    {
        $patterns = [
            '/^\d{1,2}\/\d{1,2}\/\d{4} \d{2}:\d{2}:\d{2}$/', // 20/3/2025 11:39:08
            '/^\d{4}-\d{1,2}-\d{1,2} \d{2}:\d{2}:\d{2}$/',   // 2025-03-20 11:39:08
            '/^\d{1,2}\/\d{1,2}\/\d{4}$/',                   // 20/3/2025
            '/^\d{4}-\d{1,2}-\d{1,2}$/',                     // 2025-03-20
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove duplicate rows from an array using a given field as the dedup key.
     * Keeps the last occurrence.
     *
     * @param array $rows
     * @param string $field Default 'id'
     * @return array
     */
    public static function removeDuplicates(array $rows, string $field = 'id'): array
    {
        return array_values(array_reduce($rows, function ($acc, $row) use ($field) {
            $key = mb_strtolower(trim($row[$field] ?? ''));
            if ($key === '') $key = '<<empty>>';
            $acc[$key] = $row;
            return $acc;
        }, []));
    }

}
