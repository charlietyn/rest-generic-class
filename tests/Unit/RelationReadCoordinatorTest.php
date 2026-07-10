<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Core\Services\Support\RelationReadCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationChildItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationParentItem;

final class RelationReadCoordinatorTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$booted) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
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

        self::$booted = true;
    }

    public function testListAppliesEqAndOrdering(): void
    {
        $request = Request::create('/', 'GET', [
            '_relation' => 'children',
            'eq' => json_encode(['status' => 'active']),
            'orderby' => json_encode([['name' => 'desc']]),
        ]);

        $result = $this->coordinator()->list($request, 1);

        $this->assertSame(['Gamma', 'Alpha'], collect($result['data'])->pluck('name')->all());
    }

    public function testShowFindsRelatedRecordWithinParent(): void
    {
        $request = Request::create('/', 'GET', [
            '_relation' => 'children',
            'select' => 'id,name',
        ]);

        $result = $this->coordinator()->show($request, 1, 3);

        $this->assertInstanceOf(RelationChildItem::class, $result);
        $this->assertSame('Gamma', $result->name);
        $this->assertSame(['id', 'name'], array_keys($result->getAttributes()));
    }

    public function testListPaginatesRelationResults(): void
    {
        $request = Request::create('/', 'GET', [
            '_relation' => 'children',
            'pagination' => ['page' => 2, 'pageSize' => 1],
            'orderby' => [['id' => 'asc']],
        ]);

        $result = $this->coordinator()->list($request, 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->currentPage());
        $this->assertSame(1, $result->perPage());
        $this->assertSame([2], collect($result->items())->pluck('id')->all());
    }

    public function testExportPayloadIgnoresPaginationAndResolvesColumns(): void
    {
        $request = Request::create('/', 'GET', [
            '_relation' => 'children',
            'eq' => ['status' => 'active'],
            'pagination' => ['pageSize' => 1],
            'columns' => 'id,name',
        ]);

        $payload = $this->coordinator()->exportPayload($request, 1);

        $this->assertSame(['id', 'name'], $payload['columns']);
        $this->assertSame([1, 3], collect($payload['data'])->pluck('id')->all());
        $this->assertInstanceOf(RelationChildItem::class, $payload['model']);
    }

    public function testParseParamsMergesEqAndAttrAlias(): void
    {
        $request = Request::create('/', 'GET', [
            'eq' => ['status' => 'active'],
            'attr' => ['name' => 'Alpha'],
        ]);

        $params = $this->coordinator()->parseParams($request);

        $this->assertSame([
            'status' => 'active',
            'name' => 'Alpha',
        ], $params['eq']);
    }

    private function coordinator(): RelationReadCoordinator
    {
        return new RelationReadCoordinator(
            fn (?string $relationName): array => $this->relationConfig($relationName),
            fn (array $config, mixed $parentId): RelationParentItem => RelationParentItem::findOrFail($parentId),
            new RelationQueryFilter(),
            fn (string $modelName, mixed $id, string $relation): array => [
                'success' => false,
                'model' => $modelName,
                'id' => $id,
                'relation' => $relation,
            ],
            fn (): int => (new RelationParentItem())->getPerPage()
        );
    }

    private function relationConfig(?string $relationName): array
    {
        $this->assertSame('children', $relationName);

        return [
            'relationship' => 'children',
            'relatedModel' => RelationChildItem::class,
            'parentModel' => RelationParentItem::class,
            '_type' => 'o2m',
        ];
    }
}
