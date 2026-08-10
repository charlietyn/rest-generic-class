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


    /** Cap stored error details so one broken file cannot flood the report. */
    private const MAX_REPORTED_ERRORS = 25;

    /**
     * Upsert rows from a JSON file, isolating each row so one bad row cannot
     * discard the whole table.
     *
     * Overrides the parent implementation, which wraps every row of a table in a
     * single transaction with the try/catch *outside* the loop. On PostgreSQL the
     * first failing row aborts that transaction, so the entire table is rolled
     * back — while the in-memory counters still report every row as inserted. A
     * single duplicate email once silently discarded all 5791 users and cascaded
     * into four FK-dependent tables that all reported success.
     *
     * Here each row runs inside a nested transaction, which Laravel implements as
     * a SAVEPOINT. A failing row rolls back to its own savepoint and the
     * remaining rows still commit. Counters are incremented only after the row
     * actually survives, so the report reflects what is really in the database.
     *
     * @return array{model:string,table:string,file:string,inserted:int,updated:int,
     *               skipped:int,errors:array<int,array{key:mixed,message:string}>,duration_ms:int}
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
        $failed = 0;

        $result = static fn() => [
            'model' => $modelClass,
            'table' => $table,
            'file' => $jsonPath,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        if (!is_file($jsonPath)) {
            return array_replace($result(), ['errors' => [['key' => null, 'message' => "File not found: {$jsonPath}"]]]);
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (!is_array($data)) {
            return array_replace($result(), [
                'errors' => [['key' => null, 'message' => "Invalid JSON in {$jsonPath}: " . json_last_error_msg()]],
            ]);
        }

        if (Arr::isAssoc($data)) {
            $data = [$data];
        }

        // Normalize rows: drop malformed entries, serialize nested nodes, coerce dates.
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
                if (is_array($value)) {
                    $row[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    continue;
                }
                if (is_string($value) && $format = self::detectDateFormat($value)) {
                    if (\Illuminate\Support\Carbon::hasFormat($value, $format)) {
                        $row[$key] = Carbon::createFromFormat($format, $value)->format('Y-m-d H:i:s');
                    }
                }
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            return array_replace($result(), ['skipped' => $skipped, 'errors' => $errors]);
        }

        $allColumns = array_keys(array_reduce($rows, function ($carry, $r) {
            foreach ($r as $k => $v) {
                $carry[$k] = true;
            }
            return $carry;
        }, []));
        $updateColumns = array_values(array_diff($allColumns, [$pk]));

        try {
            DB::connection($conn)->transaction(function () use (
                $conn, $table, $rows, $pk, $updateColumns, $chunkSize, &$inserted, &$updated, &$errors, &$failed
            ) {
                $db = DB::connection($conn);

                foreach (array_chunk($rows, $chunkSize) as $chunk) {
                    foreach ($chunk as $row) {
                        $keyValue = $row[$pk];

                        try {
                            // Nested transaction => SAVEPOINT: a failure here rolls
                            // back only this row, leaving the outer transaction usable.
                            $wasUpdate = $db->transaction(function () use ($db, $table, $row, $pk, $keyValue, $updateColumns) {
                                $exists = $db->table($table)->where($pk, $keyValue)->exists();

                                if ($exists) {
                                    $payload = Arr::only($row, $updateColumns);
                                    if (!empty($payload)) {
                                        $db->table($table)->where($pk, $keyValue)->update($payload);
                                    }
                                    return true;
                                }

                                $db->table($table)->insert($row);
                                return false;
                            });

                            // Only counted once the row has actually survived.
                            $wasUpdate ? $updated++ : $inserted++;
                        } catch (\Throwable $e) {
                            $failed++;
                            if ($failed <= self::MAX_REPORTED_ERRORS) {
                                $errors[] = [
                                    'key' => "{$pk}={$keyValue}",
                                    'message' => self::firstLine($e->getMessage()),
                                ];
                            }
                        }
                    }
                }
            }, 3);
        } catch (\Throwable $e) {
            // The outer commit itself failed, so nothing was persisted.
            $inserted = 0;
            $updated = 0;
            $errors[] = ['key' => $table, 'message' => 'Transaction failed: ' . self::firstLine($e->getMessage())];
        }

        if ($failed > self::MAX_REPORTED_ERRORS) {
            $errors[] = ['key' => $table, 'message' => '... and ' . ($failed - self::MAX_REPORTED_ERRORS) . ' more failing row(s).'];
        }

        return [
            'model' => $modelClass,
            'table' => $table,
            'file' => $jsonPath,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped + $failed,
            'errors' => $errors,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** Keeps driver errors readable in the console table (they span many lines). */
    private static function firstLine(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: $message);
        return mb_strlen($line) > 300 ? mb_substr($line, 0, 297) . '...' : $line;
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