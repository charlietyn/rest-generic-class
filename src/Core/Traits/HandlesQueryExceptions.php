<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HandlesQueryExceptions
 *
 * Provides consistent error handling for database query exceptions,
 * especially for undefined column errors.
 */
trait HandlesQueryExceptions
{
    /**
     * Execute a callback and catch query exceptions.
     *
     * @param callable $callback
     * @param string $context Context for logging (e.g., 'list_all', 'create')
     * @return mixed
     * @throws HttpException
     */
    protected function executeWithQueryHandling(callable $callback, string $context = 'query')
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            $this->handleQueryException($e, $context);
        } catch (\Throwable $e) {
            $this->handleGeneralException($e, $context);
        }
    }

    /**
     * Handle query exceptions and convert to user-friendly errors.
     *
     * @param QueryException $e
     * @param string $context
     * @throws HttpException
     */
    protected function handleQueryException(QueryException $e, string $context): void
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Log the full error
        Log::channel('rest-generic-class')->error("Query error in {$context}", [
            'message' => $message,
            'sql' => $e->getSql(),
            'bindings' => $e->getBindings(),
            'code' => $code,
            'trace' => $e->getTraceAsString(),
        ]);

        // Determine user-friendly message
        $userMessage = $this->parseQueryException($e);

        // Throw HTTP 400 Bad Request
        throw new HttpException(400, $userMessage);
    }

    /**
     * Parse query exception to extract user-friendly message.
     *
     * @param QueryException $e
     * @return string
     */
    protected function parseQueryException(QueryException $e): string
    {
        $message = $e->getMessage();

        // PostgreSQL: column "xyz" does not exist
        if (preg_match('/column ["\']?(\w+)["\']? does not exist/i', $message, $matches)) {
            return sprintf(
                'Invalid column: "%s". Please check your field names.',
                $matches[1]
            );
        }

        // MySQL: Unknown column 'xyz' in 'field list'
        if (preg_match('/Unknown column ["\']?(\w+)["\']? in/i', $message, $matches)) {
            return sprintf(
                'Invalid column: "%s". Please check your field names.',
                $matches[1]
            );
        }

        // PostgreSQL: relation "xyz" does not exist
        if (preg_match('/relation ["\']?(\w+)["\']? does not exist/i', $message, $matches)) {
            return sprintf(
                'Invalid table or relation: "%s".',
                $matches[1]
            );
        }

        // MySQL: Table 'db.xyz' doesn't exist
        if (preg_match('/Table ["\']?[\w.]+\.?(\w+)["\']? doesn\'t exist/i', $message, $matches)) {
            return sprintf(
                'Invalid table: "%s".',
                $matches[1]
            );
        }

        // PostgreSQL/MySQL: syntax error
        if (stripos($message, 'syntax error') !== false) {
            return 'Invalid query syntax. Please check your filter parameters.';
        }

        // Foreign key constraint
        if (stripos($message, 'foreign key constraint') !== false) {
            return 'Operation violates foreign key constraint. Related records may exist.';
        }

        // Unique constraint
        if (stripos($message, 'unique constraint') !== false ||
            stripos($message, 'duplicate entry') !== false) {
            return 'Duplicate entry. This record already exists.';
        }

        // Generic fallback
        return 'Invalid query parameters. Please verify your request.';
    }

    /**
     * Handle general exceptions.
     *
     * @param \Throwable $e
     * @param string $context
     * @throws HttpException
     */
    protected function handleGeneralException(\Throwable $e, string $context): void
    {
        // Log the error
        Log::channel('rest-generic-class')->error("Error in {$context}", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        // If it's already an HttpException, rethrow it
        if ($e instanceof HttpException) {
            throw $e;
        }

        // If it's an InvalidArgumentException (from our validators), throw as 400
        if ($e instanceof \InvalidArgumentException) {
            throw new HttpException(400, $e->getMessage());
        }

        // Generic 500 error
        $message = config('app.debug')
            ? $e->getMessage()
            : 'An error occurred while processing your request.';

        throw new HttpException(500, $message);
    }

    /**
     * Wrap a query builder operation with exception handling.
     *
     * @param callable $callback
     * @return mixed
     */
    protected function safeQuery(callable $callback)
    {
        return $this->executeWithQueryHandling($callback, 'query_execution');
    }
}