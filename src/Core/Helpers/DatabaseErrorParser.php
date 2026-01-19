<?php

namespace Ronu\RestGenericClass\Core\Helpers;

use Illuminate\Support\Str;
use function PHPUnit\Framework\throwException;

class DatabaseErrorParserException extends \Exception
{
    public function getDatabaseErrorParsed()
    {
        return json_decode($this->getMessage(),true);
    }
}

/**
 * DatabaseErrorParser
 *
 * Parses database errors from different engines (PostgreSQL, MySQL, SQL Server, MongoDB)
 * and provides human-friendly error messages with suggestions.
 *
 * Covers:
 * - SELECT errors (column/table not found, syntax errors)
 * - INSERT errors (unique constraint, foreign key, data type, null constraint)
 * - UPDATE errors (constraint violations, data truncation)
 * - DELETE errors (foreign key constraint, cascade issues)
 */
class DatabaseErrorParser
{
    /**
     * Error patterns for different database engines
     */
    private const PATTERNS = [
        'pgsql' => [
            // SELECT errors
            'undefined_column' => [
                'sqlstate' => '42703',
                'pattern' => '/column\s+["]?([^"]+)["]?\s+does not exist/i',
                'hint_pattern' => '/HINT:\s+(.+?)(?:\n|$)/i',
                'table_pattern' => '/from\s+"([^"]+)"\."([^"]+)"/i',
            ],
            'undefined_table' => [
                'sqlstate' => '42P01',
                'pattern' => '/relation\s+"([^"]+)"\s+does not exist/i',
                'hint_pattern' => '/HINT:\s+(.+?)(?:\n|$)/i',
            ],
            'syntax_error' => [
                'sqlstate' => '42601',
                'pattern' => '/syntax error at or near\s+"([^"]+)"/i',
            ],
            'duplicate_column' => [
                'sqlstate' => '42701',
                'pattern' => '/column\s+"([^"]+)"\s+specified more than once/i',
            ],

            // INSERT/UPDATE errors
            'unique_violation' => [
                'sqlstate' => '23505',
                'pattern' => '/duplicate key value violates unique constraint\s+"([^"]+)"/i',
                'detail_pattern' => '/DETAIL:\s+Key\s+\(([^)]+)\)=\(([^)]+)\)\s+already exists/i',
                'hint_pattern' => '/HINT:\s+(.+?)(?:\n|$)/i',
            ],
            'foreign_key_violation' => [
                'sqlstate' => '23503',
                'pattern' => '/(?:insert or update on table|update or delete on table)\s+"([^"]+)"\s+violates foreign key constraint\s+"([^"]+)"/i',
                'detail_pattern' => '/DETAIL:\s+(.+?)(?:\n|$)/i',
                'hint_pattern' => '/HINT:\s+(.+?)(?:\n|$)/i',
            ],
            'not_null_violation' => [
                'sqlstate' => '23502',
                'pattern' => '/null value in column\s+"([^"]+)"\s+(?:of relation\s+"([^"]+)"\s+)?violates not-null constraint/i',
                'detail_pattern' => '/DETAIL:\s+(.+?)(?:\n|$)/i',
            ],
            'check_violation' => [
                'sqlstate' => '23514',
                'pattern' => '/new row for relation\s+"([^"]+)"\s+violates check constraint\s+"([^"]+)"/i',
                'detail_pattern' => '/DETAIL:\s+(.+?)(?:\n|$)/i',
            ],
            'invalid_text_representation' => [
                'sqlstate' => '22P02',
                'pattern' => '/invalid input syntax for (?:type\s+)?(\w+):\s+"([^"]+)"/i',
            ],
            'numeric_value_out_of_range' => [
                'sqlstate' => '22003',
                'pattern' => '/(?:integer|numeric|bigint|smallint)\s+out of range/i',
            ],
            'string_data_right_truncation' => [
                'sqlstate' => '22001',
                'pattern' => '/value too long for type\s+(.+?)(?:\(|$)/i',
            ],
            'division_by_zero' => [
                'sqlstate' => '22012',
                'pattern' => '/division by zero/i',
            ],

            // DELETE errors
            'restrict_violation' => [
                'sqlstate' => '23503',
                'pattern' => '/update or delete on table\s+"([^"]+)"\s+violates foreign key constraint\s+"([^"]+)"\s+on table\s+"([^"]+)"/i',
                'detail_pattern' => '/DETAIL:\s+Key\s+\(([^)]+)\)=\(([^)]+)\)\s+is still referenced/i',
            ],
        ],

        'mysql' => [
            // SELECT errors
            'unknown_column' => [
                'sqlstate' => '42S22',
                'pattern' => '/Unknown column\s+\'([^\']+)\'\s+in\s+\'([^\']+)\'/i',
            ],
            'unknown_table' => [
                'sqlstate' => '42S02',
                'pattern' => '/Table\s+\'([^\']+)\'\s+doesn\'t exist/i',
            ],
            'syntax_error' => [
                'sqlstate' => '42000',
                'pattern' => '/You have an error in your SQL syntax.*near\s+\'([^\']+)\'/i',
            ],
            'duplicate_column' => [
                'sqlstate' => '42S21',
                'pattern' => '/Duplicate column name\s+\'([^\']+)\'/i',
            ],

            // INSERT/UPDATE errors
            'duplicate_entry' => [
                'sqlstate' => '23000',
                'pattern' => '/Duplicate entry\s+\'([^\']+)\'\s+for key\s+\'([^\']+)\'/i',
            ],
            'foreign_key_constraint' => [
                'sqlstate' => '23000',
                'pattern' => '/Cannot (?:add or update|delete or update) (?:a child row|a parent row):\s+a foreign key constraint fails\s+\(`([^`]+)`\.`([^`]+)`,\s+CONSTRAINT\s+`([^`]+)`/i',
            ],
            'column_cannot_be_null' => [
                'sqlstate' => '23000',
                'pattern' => '/Column\s+\'([^\']+)\'\s+cannot be null/i',
            ],
            'data_too_long' => [
                'sqlstate' => '22001',
                'pattern' => '/Data too long for column\s+\'([^\']+)\'\s+at row\s+(\d+)/i',
            ],
            'truncated_incorrect_value' => [
                'sqlstate' => '22007',
                'pattern' => '/(?:Incorrect|Truncated incorrect)\s+(\w+)\s+value:\s+\'([^\']+)\'/i',
            ],
            'out_of_range' => [
                'sqlstate' => '22003',
                'pattern' => '/Out of range value for column\s+\'([^\']+)\'\s+at row\s+(\d+)/i',
            ],
            'division_by_zero' => [
                'sqlstate' => '22012',
                'pattern' => '/Division by 0/i',
            ],

            // DELETE errors
            'cannot_delete_parent' => [
                'sqlstate' => '23000',
                'pattern' => '/Cannot delete or update a parent row:\s+a foreign key constraint fails/i',
            ],
        ],

        'sqlsrv' => [
            // SELECT errors
            'invalid_column' => [
                'sqlstate' => '42S22',
                'pattern' => '/Invalid column name\s+\'([^\']+)\'/i',
            ],
            'invalid_object' => [
                'sqlstate' => '42S02',
                'pattern' => '/Invalid object name\s+\'([^\']+)\'/i',
            ],
            'syntax_error' => [
                'sqlstate' => '42000',
                'pattern' => '/Incorrect syntax near\s+\'([^\']+)\'/i',
            ],

            // INSERT/UPDATE errors
            'duplicate_key' => [
                'sqlstate' => '23000',
                'pattern' => '/(?:Cannot insert duplicate key|Violation of (?:PRIMARY KEY|UNIQUE KEY) constraint)\s+\'([^\']+)\'/i',
            ],
            'foreign_key_conflict' => [
                'sqlstate' => '23000',
                'pattern' => '/(?:The (?:INSERT|UPDATE|DELETE) statement conflicted with the FOREIGN KEY constraint|FOREIGN KEY constraint)\s+\"([^\"]+)\"/i',
            ],
            'null_constraint' => [
                'sqlstate' => '23000',
                'pattern' => '/Cannot insert the value NULL into column\s+\'([^\']+)\'/i',
            ],
            'string_truncation' => [
                'sqlstate' => '22001',
                'pattern' => '/String or binary data would be truncated(?:\s+in table\s+\'([^\']+)\',\s+column\s+\'([^\']+)\')?/i',
            ],
            'conversion_failed' => [
                'sqlstate' => '22000',
                'pattern' => '/Conversion failed when converting.*(?:from|to)\s+(?:data type\s+)?(\w+)/i',
            ],
            'arithmetic_overflow' => [
                'sqlstate' => '22003',
                'pattern' => '/Arithmetic overflow error/i',
            ],
            'divide_by_zero' => [
                'sqlstate' => '22012',
                'pattern' => '/Divide by zero error/i',
            ],

            // DELETE errors
            'reference_constraint' => [
                'sqlstate' => '23000',
                'pattern' => '/The DELETE statement conflicted with the REFERENCE constraint\s+\"([^\"]+)\"/i',
            ],
        ],

        'mongodb' => [
            // Query errors
            'unknown_operator' => [
                'pattern' => '/unknown (?:top level )?operator:\s+\$([^\s]+)/i',
            ],
            'invalid_field' => [
                'pattern' => '/The field\s+\'([^\']+)\'\s+must be/i',
            ],

            // INSERT/UPDATE errors
            'duplicate_key' => [
                'pattern' => '/E11000 duplicate key error.*collection:\s+([^\s]+).*index:\s+([^\s]+).*dup key:\s+\{([^}]+)\}/i',
            ],
            'validation_failed' => [
                'pattern' => '/Document failed validation.*(?:field\s+\'([^\']+)\')?/i',
            ],
            'document_too_large' => [
                'pattern' => '/(?:document|object) (?:is )?too large/i',
            ],
            'immutable_field' => [
                'pattern' => '/(?:Performing an update on the path|Cannot update)\s+\'([^\']+)\'\s+would modify the immutable field/i',
            ],
            'invalid_type' => [
                'pattern' => '/(?:Expected type|Cannot apply \$\w+ to a value of (?:non-)?(?:type|BSON type))\s+(\w+)/i',
            ],
            'write_concern_error' => [
                'pattern' => '/Write concern error/i',
            ],
        ],
    ];

    /**
     * Parse database exception and return structured error info
     *
     * @param \Throwable $exception
     * @return array{
     *   title: string,
     *   description: string,
     *   hint: string|null,
     *   error_type: string,
     *   details: array,
     *   suggestion: string|null,
     *   operation: string|null
     * }
     */
    public static function parse(\Throwable $exception): array
    {
        $message = $exception->getMessage();
        $driver = self::detectDriver($message, $exception);

        // Extract SQLSTATE if present
        $sqlstate = self::extractSqlState($message);

        // Determine error type and parse accordingly
        $errorInfo = self::parseByDriver($message, $driver, $sqlstate);

        // Detect operation type (SELECT, INSERT, UPDATE, DELETE)
        $errorInfo['operation'] = self::detectOperation($message);

        // Build user-friendly response
        return self::buildResponse($errorInfo, $driver);
    }

    /**
     * Detect database driver from error message or exception
     */
    private static function detectDriver(string $message, \Throwable $exception): string
    {
        // From connection if available (PDOException)
        if ($exception instanceof \PDOException && isset($exception->errorInfo[0])) {
            $sqlstate = $exception->errorInfo[0];
            if (Str::startsWith($sqlstate, ['42', '23', '22'])) {
                // Check for PostgreSQL-specific patterns
                if (Str::contains($message, ['HINT:', 'LINE', 'DETAIL:'])) {
                    return 'pgsql';
                }
                return 'mysql'; // Default for SQLSTATE
            }
        }

        // From message content
        if (Str::contains($message, ['HINT:', 'LINE', 'DETAIL:', 'ERROR:  '])) {
            return 'pgsql';
        }
        if (Str::contains($message, ['Unknown column', "doesn't exist", 'Duplicate entry', 'Data too long'])) {
            return 'mysql';
        }
        if (Str::contains($message, ['Invalid column name', 'Invalid object name', 'Incorrect syntax', 'String or binary data would be truncated'])) {
            return 'sqlsrv';
        }
        if (Str::contains($message, ['E11000', 'MongoException', 'Document failed validation', 'unknown operator'])) {
            return 'mongodb';
        }

        // Try to detect from Laravel connection
        try {
            $connection = app('db')->connection();
            return $connection->getDriverName();
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Detect SQL operation type from message
     */
    private static function detectOperation(string $message): ?string
    {
        $message = strtoupper($message);

        if (Str::contains($message, ['INSERT', 'DUPLICATE KEY', 'DUPLICATE ENTRY', 'CANNOT ADD'])) {
            return 'INSERT';
        }
        if (Str::contains($message, ['UPDATE', 'CANNOT UPDATE', 'TRUNCATED'])) {
            return 'UPDATE';
        }
        if (Str::contains($message, ['DELETE', 'CANNOT DELETE', 'REMOVE'])) {
            return 'DELETE';
        }
        if (Str::contains($message, ['SELECT', 'UNKNOWN COLUMN', 'INVALID COLUMN', 'DOES NOT EXIST'])) {
            return 'SELECT';
        }

        return null;
    }

    /**
     * Extract SQLSTATE code from error message
     */
    private static function extractSqlState(string $message): ?string
    {
        if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private static function extractHint(string $hint)
    {
        $hasParentheses = Str::contains($hint, '(');
        return $hasParentheses ? Str::before($hint, '(') : $hint;
    }

    /**
     * Parse error by driver
     */
    private static function parseByDriver(string $message, string $driver, ?string $sqlstate): array
    {
        $patterns = self::PATTERNS[$driver] ?? [];

        foreach ($patterns as $errorType => $config) {
            // Check SQLSTATE match (if applicable)
            if (isset($config['sqlstate']) && $sqlstate !== $config['sqlstate']) {
                continue;
            }

            // Check pattern match
            if (preg_match($config['pattern'], $message, $matches)) {
                $info = [
                    'type' => $errorType,
                    'driver' => $driver,
                    'sqlstate' => $sqlstate,
                    'raw_message' => $message,
                ];

                // Extract specific details based on error type
                switch ($errorType) {
                    // SELECT errors (original cases)
                    case 'undefined_column':
                    case 'unknown_column':
                    case 'invalid_column':
                        $info['column'] = $matches[1] ?? null;
                        $info['context'] = $matches[2] ?? null;

                        if (isset($config['table_pattern']) && preg_match($config['table_pattern'], $message, $tableMatches)) {
                            $info['schema'] = $tableMatches[1] ?? null;
                            $info['table'] = $tableMatches[2] ?? null;
                        }

                        if (isset($config['hint_pattern']) && preg_match($config['hint_pattern'], $message, $hintMatches)) {
                            $info['hint'] = self::extractHint(trim($hintMatches[1]));
                            if (preg_match('/"([^"]+)"/', $info['hint'], $suggestedMatches)) {
                                $info['suggested_column'] = $suggestedMatches[1];
                            }
                        }
                        break;

                    case 'undefined_table':
                    case 'unknown_table':
                    case 'invalid_object':
                        $info['table'] = $matches[1] ?? null;

                        if (isset($config['hint_pattern']) && preg_match($config['hint_pattern'], $message, $hintMatches)) {
                            $info['hint'] = self::extractHint(trim($hintMatches[1]));
                        }
                        break;

                    // INSERT/UPDATE errors - Unique constraint
                    case 'unique_violation':
                    case 'duplicate_entry':
                    case 'duplicate_key':
                        $info['constraint'] = $matches[1] ?? null;
                        $info['index'] = $matches[2] ?? $matches[1] ?? null;

                        // PostgreSQL detail
                        if (isset($config['detail_pattern']) && preg_match($config['detail_pattern'], $message, $detailMatches)) {
                            $info['key'] = $detailMatches[1] ?? null;
                            $info['value'] = $detailMatches[2] ?? null;
                        }

                        // MySQL duplicate value
                        if ($driver === 'mysql' && isset($matches[1])) {
                            $info['duplicate_value'] = $matches[1];
                        }

                        if (isset($config['hint_pattern']) && preg_match($config['hint_pattern'], $message, $hintMatches)) {
                            $info['hint'] = self::extractHint(trim($hintMatches[1]));
                        }
                        break;

                    // Foreign key violations
                    case 'foreign_key_violation':
                    case 'foreign_key_constraint':
                    case 'foreign_key_conflict':
                    case 'restrict_violation':
                    case 'cannot_delete_parent':
                    case 'reference_constraint':
                        $info['table'] = $matches[1] ?? null;
                        $info['constraint'] = $matches[2] ?? $matches[1] ?? null;
                        $info['referenced_table'] = $matches[3] ?? null;

                        if (isset($config['detail_pattern']) && preg_match($config['detail_pattern'], $message, $detailMatches)) {
                            $info['detail'] = trim($detailMatches[1]);

                            // Extract referenced key from detail
                            if (preg_match('/Key\s+\(([^)]+)\)=\(([^)]+)\)/', $info['detail'], $keyMatches)) {
                                $info['key'] = $keyMatches[1];
                                $info['value'] = $keyMatches[2];
                            }
                        }

                        if (isset($config['hint_pattern']) && preg_match($config['hint_pattern'], $message, $hintMatches)) {
                            $info['hint'] = self::extractHint(trim($hintMatches[1]));
                        }
                        break;

                    // NULL constraint violations
                    case 'not_null_violation':
                    case 'column_cannot_be_null':
                    case 'null_constraint':
                        $info['column'] = $matches[1] ?? null;
                        $info['table'] = $matches[2] ?? null;

                        if (isset($config['detail_pattern']) && preg_match($config['detail_pattern'], $message, $detailMatches)) {
                            $info['detail'] = trim($detailMatches[1]);
                        }
                        break;

                    // Check constraint violations
                    case 'check_violation':
                        $info['table'] = $matches[1] ?? null;
                        $info['constraint'] = $matches[2] ?? null;

                        if (isset($config['detail_pattern']) && preg_match($config['detail_pattern'], $message, $detailMatches)) {
                            $info['detail'] = trim($detailMatches[1]);
                        }
                        break;

                    // Data type/format errors
                    case 'invalid_text_representation':
                    case 'truncated_incorrect_value':
                    case 'conversion_failed':
                    case 'invalid_type':
                        $info['data_type'] = $matches[1] ?? null;
                        $info['invalid_value'] = $matches[2] ?? null;
                        break;

                    // Data truncation
                    case 'data_too_long':
                    case 'string_data_right_truncation':
                    case 'string_truncation':
                        $info['column'] = $matches[1] ?? null;
                        $info['row'] = $matches[2] ?? null;
                        $info['table'] = $matches[2] ?? null;
                        $info['affected_column'] = $matches[3] ?? null;
                        break;

                    // Out of range
                    case 'numeric_value_out_of_range':
                    case 'out_of_range':
                    case 'arithmetic_overflow':
                        $info['column'] = $matches[1] ?? null;
                        $info['row'] = $matches[2] ?? null;
                        break;

                    // MongoDB specific
                    case 'duplicate_key':
                        if ($driver === 'mongodb') {
                            $info['collection'] = $matches[1] ?? null;
                            $info['index'] = $matches[2] ?? null;
                            $info['duplicate_key'] = $matches[3] ?? null;
                        }
                        break;

                    case 'validation_failed':
                        $info['field'] = $matches[1] ?? null;
                        break;

                    case 'immutable_field':
                        $info['field'] = $matches[1] ?? null;
                        break;

                    case 'unknown_operator':
                        $info['operator'] = $matches[1] ?? null;
                        break;

                    case 'syntax_error':
                        $info['near'] = $matches[1] ?? null;
                        break;

                    case 'duplicate_column':
                        $info['column'] = $matches[1] ?? null;
                        break;

                    case 'division_by_zero':
                    case 'divide_by_zero':
                    case 'document_too_large':
                    case 'write_concern_error':
                        // No additional extraction needed
                        break;
                }

                // Extract SQL query if present
                if (preg_match('/SQL:\s+(.+?)(?:\)|\n|$)/s', $message, $sqlMatches)) {
                    $info['sql'] = trim($sqlMatches[1]);
                }

                return $info;
            }
        }

        // Fallback: generic error
        return [
            'type' => 'generic',
            'driver' => $driver,
            'sqlstate' => $sqlstate,
            'raw_message' => $message,
        ];
    }

    /**
     * Build user-friendly response
     */
    private static function buildResponse(array $errorInfo, string $driver): array
    {
        $response = [
            'title' => '',
            'description' => '',
            'hint' => null,
            'error_type' => $errorInfo['type'],
            'operation' => $errorInfo['operation'] ?? null,
            'details' => [],
            'suggestion' => null,
        ];

        switch ($errorInfo['type']) {
            // SELECT errors (original cases remain)
            case 'undefined_column':
            case 'unknown_column':
            case 'invalid_column':
                $column = $errorInfo['column'] ?? 'unknown';
                $response['title'] = 'Column Not Found';
                $response['description'] = "The column '{$column}' does not exist in the database.";

                if (isset($errorInfo['table'])) {
                    $tableRef = isset($errorInfo['schema'])
                        ? "{$errorInfo['schema']}.{$errorInfo['table']}"
                        : $errorInfo['table'];
                    $response['description'] .= " (Table: {$tableRef})";
                }

                $response['details'] = [
                    'invalid_column' => $column,
                    'table' => $errorInfo['table'] ?? null,
                    'schema' => $errorInfo['schema'] ?? null,
                ];

                if (isset($errorInfo['hint'])) {
                    $response['hint'] = $errorInfo['hint'];
                }

                if (isset($errorInfo['suggested_column'])) {
                    $response['suggestion'] = "Did you mean '{$errorInfo['suggested_column']}'?";
                    $response['details']['suggested_column'] = $errorInfo['suggested_column'];
                } else {
                    $suggestion = self::generateColumnSuggestion($column, $errorInfo);
                    if ($suggestion) {
                        $response['suggestion'] = $suggestion;
                    }
                }
                break;

            case 'undefined_table':
            case 'unknown_table':
            case 'invalid_object':
                $table = $errorInfo['table'] ?? 'unknown';
                $response['title'] = 'Table Not Found';
                $response['description'] = "The table '{$table}' does not exist in the database.";

                $response['details'] = [
                    'invalid_table' => $table,
                ];

                if (isset($errorInfo['hint'])) {
                    $response['hint'] = $errorInfo['hint'];
                }
                break;

            // INSERT/UPDATE - Unique constraint violations
            case 'unique_violation':
            case 'duplicate_entry':
            case 'duplicate_key':
                $response['title'] = 'Duplicate Entry';

                if ($driver === 'pgsql') {
                    $constraint = $errorInfo['constraint'] ?? 'unknown';
                    $key = $errorInfo['key'] ?? 'unknown';
                    $value = $errorInfo['value'] ?? 'unknown';

                    $response['description'] = "Cannot insert duplicate value. The key '{$key}' with value '{$value}' already exists.";
                    $response['details'] = [
                        'constraint' => $constraint,
                        'key' => $key,
                        'duplicate_value' => $value,
                    ];
                } elseif ($driver === 'mysql') {
                    $value = $errorInfo['duplicate_value'] ?? 'unknown';
                    $index = $errorInfo['index'] ?? 'unknown';

                    $response['description'] = "Cannot insert duplicate entry '{$value}' for key '{$index}'.";
                    $response['details'] = [
                        'duplicate_value' => $value,
                        'index' => $index,
                    ];
                } elseif ($driver === 'sqlsrv') {
                    $constraint = $errorInfo['constraint'] ?? 'unknown';

                    $response['description'] = "Duplicate key violation on constraint '{$constraint}'.";
                    $response['details'] = [
                        'constraint' => $constraint,
                    ];
                } elseif ($driver === 'mongodb') {
                    $collection = $errorInfo['collection'] ?? 'unknown';
                    $index = $errorInfo['index'] ?? 'unknown';
                    $dupKey = $errorInfo['duplicate_key'] ?? 'unknown';

                    $response['description'] = "Duplicate key error in collection '{$collection}' on index '{$index}'.";
                    $response['details'] = [
                        'collection' => $collection,
                        'index' => $index,
                        'duplicate_key' => $dupKey,
                    ];
                }

                if (isset($errorInfo['hint'])) {
                    $response['hint'] = $errorInfo['hint'];
                }

                $response['suggestion'] = 'Either use a different value or update the existing record instead of inserting a new one.';
                break;

            // Foreign key violations
            case 'foreign_key_violation':
            case 'foreign_key_constraint':
            case 'foreign_key_conflict':
            case 'restrict_violation':
            case 'cannot_delete_parent':
            case 'reference_constraint':
                $response['title'] = 'Foreign Key Constraint Violation';

                $table = $errorInfo['table'] ?? 'unknown';
                $constraint = $errorInfo['constraint'] ?? 'unknown';

                if (isset($errorInfo['operation']) && $errorInfo['operation'] === 'DELETE') {
                    $refTable = $errorInfo['referenced_table'] ?? 'another table';
                    $response['description'] = "Cannot delete from table '{$table}' because records in '{$refTable}' still reference it.";
                    $response['suggestion'] = "Delete the referencing records first, or use CASCADE delete if appropriate.";
                } else {
                    $response['description'] = "Foreign key constraint '{$constraint}' on table '{$table}' has been violated.";
                    $response['suggestion'] = "Ensure the referenced record exists before inserting/updating.";
                }

                $response['details'] = [
                    'table' => $table,
                    'constraint' => $constraint,
                    'referenced_table' => $errorInfo['referenced_table'] ?? null,
                ];

                if (isset($errorInfo['key'])) {
                    $response['details']['key'] = $errorInfo['key'];
                    $response['details']['value'] = $errorInfo['value'] ?? null;
                }

                if (isset($errorInfo['detail'])) {
                    $response['details']['detail'] = $errorInfo['detail'];
                }

                if (isset($errorInfo['hint'])) {
                    $response['hint'] = $errorInfo['hint'];
                }
                break;

            // NULL constraint violations
            case 'not_null_violation':
            case 'column_cannot_be_null':
            case 'null_constraint':
                $column = $errorInfo['column'] ?? 'unknown';
                $table = $errorInfo['table'] ?? '';

                $response['title'] = 'NULL Value Not Allowed';
                $response['description'] = "Column '{$column}'" . ($table ? " in table '{$table}'" : '') . " cannot be NULL.";
                $response['suggestion'] = "Provide a value for '{$column}' or set a default value in the database schema.";

                $response['details'] = [
                    'column' => $column,
                    'table' => $table ?: null,
                ];

                if (isset($errorInfo['detail'])) {
                    $response['details']['detail'] = $errorInfo['detail'];
                }
                break;

            // Check constraint violations
            case 'check_violation':
                $table = $errorInfo['table'] ?? 'unknown';
                $constraint = $errorInfo['constraint'] ?? 'unknown';

                $response['title'] = 'Check Constraint Violation';
                $response['description'] = "Data violates check constraint '{$constraint}' on table '{$table}'.";
                $response['suggestion'] = "Ensure the data meets the validation rules defined in the constraint.";

                $response['details'] = [
                    'table' => $table,
                    'constraint' => $constraint,
                ];

                if (isset($errorInfo['detail'])) {
                    $response['details']['detail'] = $errorInfo['detail'];
                    $response['hint'] = $errorInfo['detail'];
                }
                break;

            // Data type/format errors
            case 'invalid_text_representation':
            case 'truncated_incorrect_value':
            case 'conversion_failed':
            case 'invalid_type':
                $dataType = $errorInfo['data_type'] ?? 'unknown';
                $invalidValue = $errorInfo['invalid_value'] ?? 'unknown';

                $response['title'] = 'Invalid Data Type';
                $response['description'] = "Cannot convert value '{$invalidValue}' to type '{$dataType}'.";
                $response['suggestion'] = "Ensure the value matches the expected data type (e.g., numbers for integer columns, valid dates for date columns).";

                $response['details'] = [
                    'expected_type' => $dataType,
                    'invalid_value' => $invalidValue,
                ];
                break;

            // Data truncation
            case 'data_too_long':
            case 'string_data_right_truncation':
            case 'string_truncation':
                $column = $errorInfo['column'] ?? ($errorInfo['affected_column'] ?? 'unknown');
                $table = $errorInfo['table'] ?? null;

                $response['title'] = 'Data Too Long';
                $response['description'] = "The data is too long for column '{$column}'" . ($table ? " in table '{$table}'" : '') . ".";
                $response['suggestion'] = "Reduce the length of the data or increase the column size in the database schema.";

                $response['details'] = [
                    'column' => $column,
                    'table' => $table,
                    'row' => $errorInfo['row'] ?? null,
                ];
                break;

            // Out of range
            case 'numeric_value_out_of_range':
            case 'out_of_range':
            case 'arithmetic_overflow':
                $column = $errorInfo['column'] ?? 'unknown';

                $response['title'] = 'Numeric Value Out of Range';
                $response['description'] = "The numeric value for column '{$column}' is out of the allowed range.";
                $response['suggestion'] = "Use a smaller number or change the column type to support larger values (e.g., BIGINT instead of INTEGER).";

                $response['details'] = [
                    'column' => $column,
                    'row' => $errorInfo['row'] ?? null,
                ];
                break;

            // Division by zero
            case 'division_by_zero':
            case 'divide_by_zero':
                $response['title'] = 'Division by Zero';
                $response['description'] = "Attempted to divide by zero in a calculation.";
                $response['suggestion'] = "Check your calculations and ensure denominators are never zero.";
                break;

            // MongoDB specific
            case 'validation_failed':
                $field = $errorInfo['field'] ?? 'unknown';

                $response['title'] = 'Document Validation Failed';
                $response['description'] = "Document validation failed" . ($field !== 'unknown' ? " for field '{$field}'" : '') . ".";
                $response['suggestion'] = "Ensure the document structure matches the collection's validation schema.";

                $response['details'] = [
                    'field' => $field !== 'unknown' ? $field : null,
                ];
                break;

            case 'document_too_large':
                $response['title'] = 'Document Too Large';
                $response['description'] = "The document exceeds MongoDB's maximum size limit (16MB).";
                $response['suggestion'] = "Split the document into smaller pieces or store large data externally (e.g., GridFS).";
                break;

            case 'immutable_field':
                $field = $errorInfo['field'] ?? 'unknown';

                $response['title'] = 'Immutable Field';
                $response['description'] = "Cannot update immutable field '{$field}'.";
                $response['suggestion'] = "Remove '{$field}' from your update operation or create a new document instead.";

                $response['details'] = [
                    'field' => $field,
                ];
                break;

            case 'write_concern_error':
                $response['title'] = 'Write Concern Error';
                $response['description'] = "MongoDB could not satisfy the write concern for this operation.";
                $response['suggestion'] = "Check your replica set configuration and ensure sufficient nodes are available.";
                break;

            // Generic MongoDB operator error
            case 'unknown_operator':
                $operator = $errorInfo['operator'] ?? 'unknown';

                $response['title'] = 'Unknown MongoDB Operator';
                $response['description'] = "The operator '\${$operator}' is not recognized by MongoDB.";
                $response['suggestion'] = "Check MongoDB documentation for valid query operators.";

                $response['details'] = [
                    'invalid_operator' => $operator,
                ];
                break;

            // SQL syntax errors
            case 'syntax_error':
                $near = $errorInfo['near'] ?? 'unknown';

                $response['title'] = 'SQL Syntax Error';
                $response['description'] = "Invalid SQL syntax near '{$near}'.";
                $response['suggestion'] = 'Check your query syntax and ensure all SQL keywords are correctly spelled.';

                $response['details'] = [
                    'near' => $near,
                ];
                break;

            case 'duplicate_column':
                $column = $errorInfo['column'] ?? 'unknown';

                $response['title'] = 'Duplicate Column';
                $response['description'] = "The column '{$column}' is specified more than once in the query.";
                $response['suggestion'] = 'Remove duplicate column references from your SELECT or WHERE clauses.';

                $response['details'] = [
                    'duplicate_column' => $column,
                ];
                break;

            // Generic fallback
            default:
                $response['title'] = 'Database Error';
                $response['description'] = 'An error occurred while executing the database operation.';

                if (isset($errorInfo['raw_message'])) {
                    $response['details']['raw_error'] = Str::limit($errorInfo['raw_message'], 200);
                }
                break;
        }

        // Add SQL query to details if available (truncated for security)
        if (isset($errorInfo['sql'])) {
            $response['details']['sql'] = Str::limit($errorInfo['sql'], 500);
        }

        // Add SQLSTATE
        if (isset($errorInfo['sqlstate'])) {
            $response['details']['sqlstate'] = $errorInfo['sqlstate'];
        }

        // Add driver info
        $response['details']['database_driver'] = $driver;

        return $response;
    }

    /**
     * Generate column suggestion based on typo detection
     */
    private static function generateColumnSuggestion(string $column, array $errorInfo): ?string
    {
        // Common typos patterns
        $commonTypos = [
            'descrwiption' => 'description',
            'namwe' => 'name',
            'namew' => 'name',
            'deswcription' => 'description',
            'emial' => 'email',
            'craeted_at' => 'created_at',
            'udpated_at' => 'updated_at',
            'statsu' => 'status',
            'adress' => 'address',
            'telefone' => 'telephone',
        ];

        $lowerColumn = strtolower($column);
        if (isset($commonTypos[$lowerColumn])) {
            return "Check if you meant '{$commonTypos[$lowerColumn]}' instead of '{$column}'.";
        }

        // Detect doubled letters (typing too fast)
        if (preg_match('/(.)\1{2,}/', $column)) {
            $suggestion = preg_replace('/(.)\1+/', '$1', $column);
            return "Check if you meant '{$suggestion}' (remove duplicate letters).";
        }

        return null;
    }

    /**
     * Format response as HTTP JSON response
     */
    public static function toExceptionError(array $parsedError, int $statusCode = 400): \DatabaseErrorParserException
    {
        $response = [
            'error' => [
                'title' => $parsedError['title'],
                'message' => $parsedError['description'],
                'type' => $parsedError['error_type'],
            ],
        ];

        if ($parsedError['operation']) {
            $response['error']['operation'] = $parsedError['operation'];
        }
        if ($parsedError['suggestion']) {
            $response['error']['suggestion'] = $parsedError['suggestion'];
        } else if ($parsedError['hint']) {
            $response['error']['hint'] = $parsedError['hint'];
        }

        // Include details only in debug mode
        if (config('app.debug')) {
            $response['error']['details'] = $parsedError['details'];
        }
        throw new DatabaseErrorParserException(json_encode($response), $statusCode);
    }

    /**
     * Format response as plain text (for logs)
     */
    public static function toPlainText(array $parsedError): string
    {
        $text = "{$parsedError['title']}: {$parsedError['description']}";

        if ($parsedError['operation']) {
            $text = "[{$parsedError['operation']}] " . $text;
        }

        if ($parsedError['hint']) {
            $text .= "\nHint: {$parsedError['hint']}";
        }

        if ($parsedError['suggestion']) {
            $text .= "\nSuggestion: {$parsedError['suggestion']}";
        }

        return $text;
    }
}