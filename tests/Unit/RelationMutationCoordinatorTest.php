<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\RelationMutationCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationChildItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationParentItem;

final class RelationMutationCoordinatorTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('relation_parent_items', function ($table) {
                $table->increments('id');
                $table->string('name');
            });

            Capsule::schema()->create('relation_child_items', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('parent_id');
                $table->string('name');
                $table->string('status');
            });

            self::$booted = true;
        }

        $this->seedRows();
    }

    public function testCreateRelationCreatesChildThroughParentRelationship(): void
    {
        $request = Request::create('/', 'POST', [
            '_relation' => 'children',
            'name' => 'Created',
            'status' => 'active',
        ]);

        $response = $this->coordinator()->create($request, 1);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['model']['parent_id']);
        $this->assertSame('Created', RelationChildItem::query()->find($payload['model']['id'])->name);
    }

    public function testUpdateRelationUpdatesRelatedRecord(): void
    {
        $request = Request::create('/', 'PUT', [
            '_relation' => 'children',
            'name' => 'Renamed',
            'status' => 'active',
        ]);

        $response = $this->coordinator()->update($request, 1, 2);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('Renamed', RelationChildItem::query()->find(2)->name);
    }

    public function testBulkUpdateReportsMissingIdsAndKeepsUpdatedModels(): void
    {
        $request = Request::create('/', 'PUT', [
            '_relation' => 'children',
            '_scenario' => 'bulk_update',
            'children' => [
                ['id' => 1, 'name' => 'Bulk A', 'status' => 'active'],
                ['id' => 999, 'name' => 'Missing', 'status' => 'active'],
            ],
        ]);

        $response = $this->coordinator()->update($request, 1);
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame([999], $payload['error']['not_found_ids']);
        $this->assertSame('Bulk A', RelationChildItem::query()->find(1)->name);
    }

    public function testBulkDeleteDeletesFoundRecordsAndReportsMissingIds(): void
    {
        $request = Request::create('/', 'DELETE', [
            '_relation' => 'children',
            '_scenario' => 'bulk_delete',
            'children' => [1, 999],
        ]);

        $response = $this->coordinator()->delete($request, 1);
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame([999], $payload['error']['not_found_ids']);
        $this->assertNull(RelationChildItem::query()->find(1));
        $this->assertNotNull(RelationChildItem::query()->find(2));
    }

    private function coordinator(): RelationMutationCoordinator
    {
        return new RelationMutationCoordinator(
            fn (?string $relationName): array => $this->relationConfig($relationName),
            fn (array $config, mixed $parentId): RelationParentItem => RelationParentItem::query()->findOrFail($parentId),
            fn (array $config, bool $bulk, mixed $parentIdOrRelatedId, mixed $relatedId): array => [
                RelationParentItem::query()->findOrFail($parentIdOrRelatedId),
                $relatedId,
            ],
            fn (Request $request, array $config): array => $this->extractData($request, $config),
            fn (Request $request, callable $operation, int $status = 200): JsonResponse => new JsonResponse($operation(), $status),
            fn (Request $request): bool => str_contains($request->get('_scenario', ''), 'bulk'),
            fn (string $modelName, mixed $id, string $relation): array => [
                'success' => false,
                'error' => [
                    'model' => $modelName,
                    'id' => $id,
                    'relation' => $relation,
                ],
            ],
            fn (string $modelName, array $notFoundIds, string $relation): array => [
                'model' => $modelName,
                'relation' => $relation,
                'not_found_ids' => $notFoundIds,
            ]
        );
    }

    private function relationConfig(?string $relationName): array
    {
        $this->assertSame('children', $relationName);

        return [
            'relationship' => 'children',
            'relatedModel' => RelationChildItem::class,
            'parentModel' => RelationParentItem::class,
            'mutation' => [
                'dataKey' => 'children',
                'deleteRelated' => true,
            ],
            '_type' => 'o2m',
        ];
    }

    private function extractData(Request $request, array $config): array
    {
        $params = $request->all();
        $dataKey = $config['mutation']['dataKey'];

        if (isset($params[$dataKey])) {
            return $params[$dataKey];
        }

        unset($params['_relation'], $params['_scenario']);
        return $params;
    }

    private function seedRows(): void
    {
        RelationChildItem::query()->delete();
        RelationParentItem::query()->delete();

        RelationParentItem::insert([
            ['id' => 1, 'name' => 'Parent A'],
            ['id' => 2, 'name' => 'Parent B'],
        ]);

        RelationChildItem::insert([
            ['id' => 1, 'parent_id' => 1, 'name' => 'Alpha', 'status' => 'active'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Beta', 'status' => 'inactive'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Gamma', 'status' => 'active'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'Delta', 'status' => 'active'],
        ]);
    }
}
