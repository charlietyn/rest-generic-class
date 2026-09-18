<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Helpers\DatabaseErrorParser;

/**
 * Covers the operation reported by DatabaseErrorParser::parse. A soft delete
 * filter puts "deleted_at" in the message, and a plain substring match on
 * "DELETE" used to label every such error as a DELETE statement.
 */
final class DatabaseErrorParserOperationTest extends TestCase
{
    public function testColumnErrorMentioningDeletedAtIsReportedAsSelect(): void
    {
        $parsed = DatabaseErrorParser::parse(new \Exception(
            'SQLSTATE[42703]: Undefined column: 7 ERROR: column "deleted_at" does not exist'
        ));

        $this->assertSame('SELECT', $parsed['operation']);
    }

    public function testRealDeleteStatementIsStillReportedAsDelete(): void
    {
        $parsed = DatabaseErrorParser::parse(new \Exception(
            'SQLSTATE[23503]: Foreign key violation: 7 ERROR: delete on table "users" '
            . 'violates foreign key constraint "orders_user_id_foreign" on table "orders" '
            . '(SQL: delete from "users" where "id" = 1)'
        ));

        $this->assertSame('DELETE', $parsed['operation']);
    }
}
