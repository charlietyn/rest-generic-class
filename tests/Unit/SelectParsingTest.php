<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Helpers\Helper;

/**
 * Covers Helper::parseSelect, the single normalization point for the `select`
 * query parameter. Before it existed the listing path handed Eloquent the raw
 * JSON string, which Postgres then read as one 63-char column name (42703).
 */
final class SelectParsingTest extends TestCase
{
    public function testDecodesJsonArray(): void
    {
        // The reported bug: select=["id","code"] arrived as a single column.
        $this->assertSame(['id', 'code'], Helper::parseSelect('["id","code"]'));
    }

    public function testSplitsCommaSeparatedList(): void
    {
        $this->assertSame(['id', 'code'], Helper::parseSelect('id,code'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame(['id', 'code'], Helper::parseSelect('id, code '));
    }

    public function testWrapsSingleColumn(): void
    {
        $this->assertSame(['id'], Helper::parseSelect('id'));
    }

    public function testLeavesDecodedArrayUntouched(): void
    {
        $this->assertSame(['id', 'code'], Helper::parseSelect(['id', 'code']));
    }

    public function testFallsBackToWildcardOnEmptyValues(): void
    {
        $this->assertSame(['*'], Helper::parseSelect(null));
        $this->assertSame(['*'], Helper::parseSelect(''));
        $this->assertSame(['*'], Helper::parseSelect([]));
    }

    public function testKeepsExplicitWildcard(): void
    {
        $this->assertSame(['*'], Helper::parseSelect('*'));
    }

    public function testNumericStringStaysAStringColumn(): void
    {
        // Regression on the old `$decoded ?: ...`: json_decode('5') is int 5.
        $this->assertSame(['5'], Helper::parseSelect('5'));
    }

    public function testJsonStringScalarBecomesAnArray(): void
    {
        // Regression on the old `$decoded ?: ...`: json_decode('"id"') is a string.
        $this->assertSame(['id'], Helper::parseSelect('"id"'));
    }
}
