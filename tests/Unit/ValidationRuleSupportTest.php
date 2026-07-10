<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Validation\ValidationRuleSupport;

final class ValidationRuleSupportTest extends TestCase
{
    public function testNormalizeValuePreservesLegacySkipCases(): void
    {
        $this->assertNull(ValidationRuleSupport::normalizeValue(null));
        $this->assertNull(ValidationRuleSupport::normalizeValue(''));
        $this->assertNull(ValidationRuleSupport::normalizeValue([]));
        $this->assertSame([5], ValidationRuleSupport::normalizeValue(5));
        $this->assertSame([1, 2], ValidationRuleSupport::normalizeValue([1, 2]));
    }

    public function testExtractIdsHandlesScalarsArraysObjectsAndDedupes(): void
    {
        $object = new class {
            public int $id = 3;
        };

        $this->assertSame(
            [1, 2, 3],
            ValidationRuleSupport::extractIds([1, ['id' => 2], $object, '', null, 1])
        );
    }

    public function testErrorMessagesRemainByteCompatible(): void
    {
        $validator = $this->makeValidator();

        ValidationRuleSupport::addNoIdsError($validator, 'roles', 'id');
        ValidationRuleSupport::addMissingIdsError($validator, 'roles', [4, 5], [
            'tenant_id' => 7,
            'status' => ['active', 'pending'],
        ]);

        $this->assertSame([
            'Theres no IDs provided to validate.:id',
            'The following IDs do not exist: 4, 5 (conditions: tenant_id=7, status=[active, pending])',
        ], $validator->errors()->get('roles'));
    }

    private function makeValidator(): Validator
    {
        return new Validator(new Translator(new ArrayLoader(), 'en'), [], []);
    }
}
