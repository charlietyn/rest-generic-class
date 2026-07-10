<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\HierarchyCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\HierarchyItem;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class HierarchyCoordinatorTest extends TestCase
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

        Capsule::schema()->create('hierarchy_items', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name');
        });

        HierarchyItem::insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'Root A'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Child A1'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'Grandchild A1'],
            ['id' => 4, 'parent_id' => null, 'name' => 'Root B'],
            ['id' => 5, 'parent_id' => 4, 'name' => 'Child B1'],
        ]);

        self::$booted = true;
    }

    public function testListBuildsTreeWithCustomChildrenKey(): void
    {
        $result = $this->coordinator()->list([
            'hierarchy' => [
                'children_key' => 'nodes',
                'include_empty_children' => false,
            ],
        ]);

        $this->assertSame([1, 4], collect($result['data'])->pluck('id')->all());
        $this->assertSame(2, $result['data'][0]['nodes'][0]['id']);
        $this->assertSame(3, $result['data'][0]['nodes'][0]['nodes'][0]['id']);
        $this->assertArrayNotHasKey('nodes', $result['data'][0]['nodes'][0]['nodes'][0]);
    }

    public function testShowWithDescendantsReturnsRequestedBranch(): void
    {
        $result = $this->coordinator()->show(['hierarchy' => true], 2);

        $this->assertSame(2, $result['id']);
        $this->assertSame([3], collect($result['children'])->pluck('id')->all());
    }

    public function testShowHonorsCustomChildrenKey(): void
    {
        $result = $this->coordinator()->show([
            'hierarchy' => ['children_key' => 'items'],
        ], 1);

        $this->assertArrayHasKey('items', $result);
        $this->assertSame(2, $result['items'][0]['id']);
    }

    public function testListPaginationAppliesToRootsOnly(): void
    {
        $result = $this->coordinator()->list([
            'hierarchy' => true,
            'pagination' => ['page' => 1, 'pageSize' => 1],
        ]);

        $this->assertSame(1, $result['current_page']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['per_page']);
        $this->assertSame([1], collect($result['data'])->pluck('id')->all());
    }

    public function testDisabledHierarchyDelegatesToFallback(): void
    {
        $result = $this->coordinator()->list([
            'hierarchy' => ['enabled' => false],
        ]);

        $this->assertSame(['fallback' => 'list'], $result);
    }

    public function testInvalidFilterModeThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid hierarchy filter_mode');

        $this->coordinator()->list([
            'hierarchy' => ['filter_mode' => 'unknown'],
        ]);
    }

    private function coordinator(): HierarchyCoordinator
    {
        return new HierarchyCoordinator(
            new HierarchyItem(),
            fn (array $params, Builder $query): Builder => $query->orderBy('id'),
            fn (Builder $query, mixed $relations, mixed $oper = null): Builder => $query,
            fn (array $params, bool $toJson = true): array => ['fallback' => 'list'],
            fn (array $params, mixed $id): HierarchyItem => HierarchyItem::findOrFail($id)
        );
    }
}
