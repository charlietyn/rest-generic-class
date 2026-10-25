<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\BootsContractDatabase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ContractUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What a model hides is neither returned nor queryable (the same rule as the NestJS libraries).
 * KI-HIDDEN-ON-CREATE and KI-HIDDEN-FILTERABLE of the apps-generator contract suite.
 */
final class HiddenColumnsTest extends TestCase
{
    use BootsContractDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContractDatabase();
    }

    protected function tearDown(): void
    {
        $this->restoreContractDatabase();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // create answers the serialised model
    // -------------------------------------------------------------------------

    public function testCreateDoesNotReturnHiddenAttributes(): void
    {
        $result = (new BaseService(ContractUser::class))->create([
            'name' => 'New', 'password' => '$2y$12$secret', 'active' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('New', $result['model']['name']);
        $this->assertArrayNotHasKey('password', $result['model']);
        $this->assertArrayNotHasKey('created_at', $result['model']);
        $this->assertArrayNotHasKey('updated_at', $result['model']);
    }

    public function testCreateAppliesCasts(): void
    {
        $result = (new BaseService(ContractUser::class))->create(['name' => 'New', 'active' => 1]);

        $this->assertTrue($result['model']['active']);
    }

    public function testCreateOfAListDoesNotReturnHiddenAttributes(): void
    {
        $result = (new BaseService(ContractUser::class))->create([
            'contractuser' => [['name' => 'A', 'password' => 'x'], ['name' => 'B', 'password' => 'y']],
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('password', $result[0]['model']);
        $this->assertArrayNotHasKey('password', $result[1]['model']);
    }

    // -------------------------------------------------------------------------
    // a hidden column is not an oracle
    // -------------------------------------------------------------------------

    public static function hiddenQueries(): array
    {
        return [
            'oper' => [['oper' => ['and' => ['password|like|$2y$%']]], "Column 'password' of ContractUser cannot be used to filter."],
            'oper in a nested group' => [['oper' => ['and' => ['or' => ['name|=|x', 'password|like|$2y$%']]]], "Column 'password' of ContractUser cannot be used to filter."],
            'oper on a hidden timestamp' => [['oper' => ['and' => ['created_at|>|2026-01-02']]], "Column 'created_at' of ContractUser cannot be used to filter."],
            'qualified column' => [['oper' => ['and' => ['contract_users.password|like|$2y$%']]], "Column 'password' of ContractUser cannot be used to filter."],
            'JSON path of a hidden column' => [['oper' => ['and' => ['password->hash|like|$2y$%']]], "Column 'password' of ContractUser cannot be used to filter."],
            'orderby a JSON path of a hidden column' => [['orderby' => [['password->hash' => 'asc']]], "Column 'password' of ContractUser cannot be used to sort."],
            'attr' => [['attr' => ['password' => '$2y$12$vet']], "Column 'password' of ContractUser cannot be used to filter."],
            'attr as JSON' => [['attr' => '{"updated_at":"2026-01-03 00:00:00"}'], "Column 'updated_at' of ContractUser cannot be used to filter."],
            'orderby' => [['orderby' => [['password' => 'asc']]], "Column 'password' of ContractUser cannot be used to sort."],
            'orderby a hidden timestamp' => [['orderby' => [['created_at' => 'desc']]], "Column 'created_at' of ContractUser cannot be used to sort."],
            'relation filter' => [['oper' => ['devices' => ['and' => ['device_token|like|apns-%']]]], "Column 'device_token' of ContractDevice cannot be used to filter."],
            'orderby through a relation' => [['orderby' => [['father.password' => 'asc']]], "Column 'password' of ContractUser cannot be used to sort."],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hiddenQueries')]
    public function testHiddenColumnIsNotQueryable(array $params, string $message): void
    {
        try {
            (new BaseService(ContractUser::class))->list_all($params);
            $this->fail('A hidden column was accepted in a query.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testVisibleColumnsKeepWorking(): void
    {
        $service = new BaseService(ContractUser::class);

        $this->assertSame([3], $this->ids($service->list_all(['oper' => ['and' => ['name|like|V%']]])));
        $this->assertSame([3, 4], $this->ids($service->list_all(['attr' => ['father_id' => 2]])));
        $this->assertSame([5, 4, 3, 2, 1], $this->ids($service->list_all(['orderby' => [['id' => 'desc']]])));
        $this->assertSame([3], $this->ids($service->list_all(['oper' => ['devices' => ['and' => ['platform|=|ios']]]])));
    }

    public function testHiddenColumnInSelectIsDroppedSilently(): void
    {
        $rows = (new BaseService(ContractUser::class))->list_all([
            'select' => ['id', 'password', 'name'],
            'oper' => ['and' => ['id|=|3']],
        ])['data'];

        $this->assertSame([['id' => 3, 'name' => 'Vet']], $rows);
    }

    public function testOnlyHiddenColumnsInSelectFallBackToTheVisibleRow(): void
    {
        $rows = (new BaseService(ContractUser::class))->list_all([
            'select' => ['password'],
            'oper' => ['and' => ['id|=|3']],
        ])['data'];

        $this->assertSame(3, $rows[0]['id']);
        $this->assertArrayNotHasKey('password', $rows[0]);
    }

    public static function hiddenRelationEndpointQueries(): array
    {
        return [
            'eq' => ['applyEq', ['password' => '$2y$12$vet'], "Column 'password' of ContractUser cannot be used to filter."],
            'oper' => ['applyOper', ['and' => ['password like $2y%']], "Column 'password' of ContractUser cannot be used to filter."],
            'oper is null' => ['applyOper', ['or' => ['created_at null']], "Column 'created_at' of ContractUser cannot be used to filter."],
            'orderby' => ['applyOrdering', [['updated_at' => 'asc']], "Column 'updated_at' of ContractUser cannot be used to sort."],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hiddenRelationEndpointQueries')]
    public function testHiddenColumnIsNotQueryableThroughARelationEndpoint(string $method, array $value, string $message): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage($message);

        (new RelationQueryFilter())->{$method}(ContractUser::findOrFail(2)->children(), $value);
    }

    public function testHiddenColumnsCanBeAllowedByConfiguration(): void
    {
        config()->set('rest-generic-class.filtering.allow_hidden_columns', true);

        $result = (new BaseService(ContractUser::class))->list_all(['oper' => ['and' => ['password|=|$2y$12$vet']]]);

        $this->assertSame([3], $this->ids($result));
    }
}
