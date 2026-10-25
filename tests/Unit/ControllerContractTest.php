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
