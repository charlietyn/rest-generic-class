<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Controllers\RestController;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\BootsContractDatabase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ContractUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What RestController reads from the request.
 */
final class ControllerContractTest extends TestCase
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

    private function controller(): RestController
    {
        return new class extends RestController {
            public function __construct()
            {
                $this->modelClass = ContractUser::class;
                $this->service = new BaseService(ContractUser::class);
            }
        };
    }

    private function remaining(): array
    {
        return ContractUser::query()->orderBy('id')->pluck('id')->all();
    }

    // -------------------------------------------------------------------------
    // delete-by-id accepts every bulk envelope (KI-DELETE-BY-ID-ENVELOPE)
    // -------------------------------------------------------------------------

    public static function bulkBodies(): array
    {
        return [
            '{"ids": [...]}' => [['ids' => [3, 4]]],
            '{"<model>": [...]}' => [['contractuser' => [3, 4]]],
            'bare list' => [[3, 4]],
        ];
    }

    #[DataProvider('bulkBodies')]
    public function testDeleteByIdAcceptsEveryBulkEnvelope(array $body): void
    {
        $request = Request::create('/', 'DELETE', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));

        $result = $this->controller()->deleteById($request);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 2, 5], $this->remaining());
    }

    // -------------------------------------------------------------------------
    // bulk-update / bulk create read the key the generated rules validate (KI-BULK-UPDATE-UNVALIDATED)
    // -------------------------------------------------------------------------

    public static function bulkKeys(): array
    {
        // strtolower(MODEL) is what the library read; Str::snake(MODEL) is what the generated rules validate.
        return ['lower-case model' => ['contractuser'], 'snake_case model' => ['contract_user']];
    }

    #[DataProvider('bulkKeys')]
    public function testBulkUpdateReadsEitherEntityKey(string $key): void
    {
        $request = Request::create('/', 'POST', [$key => [['id' => 3, 'name' => 'Vet 2'], ['id' => 4, 'name' => 'Tech 2']]]);

        $result = $this->controller()->updateMultiple($request);

        $this->assertTrue($result['success']);
        $this->assertSame(['Vet 2', 'Tech 2'], ContractUser::query()->whereIn('id', [3, 4])->orderBy('id')->pluck('name')->all());
    }

    #[DataProvider('bulkKeys')]
    public function testBulkCreateReadsEitherEntityKey(string $key): void
    {
        $result = (new BaseService(ContractUser::class))->create([$key => [['name' => 'A'], ['name' => 'B']]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, ContractUser::query()->whereIn('name', ['A', 'B'])->count());
    }

    public static function invalidBulkUpdates(): array
    {
        return [
            'no entity key' => [['other' => [['id' => 3]]], "bulk-update: the body must carry 'contractuser' or 'contract_user' with a list of records."],
            'not a list' => [['contract_user' => 'x'], "bulk-update: the body must carry 'contractuser' or 'contract_user' with a list of records."],
            'item without id' => [['contract_user' => [['name' => 'x']]], "bulk-update: record 0 has no 'id'."],
        ];
    }

    #[DataProvider('invalidBulkUpdates')]
    public function testInvalidBulkUpdateIsABadRequest(array $body, string $message): void
    {
        try {
            $this->controller()->updateMultiple(Request::create('/', 'POST', $body));
            $this->fail('Expected a 400');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testDeleteByIdAcceptsTheSnakeCaseEntityKey(): void
    {
        $request = Request::create('/', 'DELETE', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['contract_user' => [3, 4]]));

        $this->assertTrue($this->controller()->deleteById($request)['success']);
        $this->assertSame([1, 2, 5], $this->remaining());
    }

    // -------------------------------------------------------------------------
    // eq + attr (KI-EQ-ATTR-MERGE) — attr wins a repeated key
    // -------------------------------------------------------------------------

    public function testEqAndAttrSentTogetherAreMerged(): void
    {
        $controller = $this->controller();
        $params = $controller->process_request(Request::create('/', 'GET', [
            'eq' => '{"father_id":2}',
            'attr' => '{"id":[3,5]}',
        ]));

        $this->assertSame(['father_id' => 2, 'id' => [3, 5]], $params['attr']);
        $this->assertSame([3], $this->ids((new BaseService(ContractUser::class))->list_all($params)));
    }

    public function testAttrWinsARepeatedKey(): void
    {
        $params = $this->controller()->process_request(Request::create('/', 'GET', [
            'eq' => ['father_id' => 2],
            'attr' => '{"father_id":1}',
        ]));

        $this->assertSame(['father_id' => 1], $params['attr']);
        $this->assertSame([2], $this->ids((new BaseService(ContractUser::class))->list_all($params)));
    }

    public function testInvalidJsonInEqOrAttrIsABadRequest(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The eq parameter is not valid JSON.');

        $this->controller()->process_request(Request::create('/', 'GET', ['eq' => '{bad', 'attr' => '{"id":1}']));
    }
}
