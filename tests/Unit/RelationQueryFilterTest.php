<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationChildItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationParentItem;

final class RelationQueryFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->dropIfExists('relation_child_items');
        Capsule::schema()->dropIfExists('relation_parent_items');

        Capsule::schema()->create('relation_parent_items', function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        Capsule::schema()->create('relation_child_items', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
        });

        RelationParentItem::insert([
            ['id' => 1, 'name' => 'Parent A'],
        ]);

        RelationChildItem::insert([
            ['id' => 1, 'parent_id' => 1, 'name' => 'Alpha', 'status' => 'active'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Beta', 'status' => 'inactive'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Gamma', 'status' => 'active'],
            ['id' => 4, 'parent_id' => 1, 'name' => null, 'status' => 'archived'],
        ]);
    }

    public function testApplyEqSkipsRouteMetadataAndHandlesArraysAndNulls(): void
    {
        $query = RelationParentItem::findOrFail(1)->children();

        $this->filter()->applyEq($query, [
            '_relation' => 'children',
            '_scenario' => 'list',
            'status' => ['active', 'archived'],
            'name' => null,
        ]);

        $this->assertSame([4], $query->pluck('id')->all());
    }

    public function testApplyOperSupportsComparisonLikeInAndBetweenConditions(): void
    {
        $query = RelationParentItem::findOrFail(1)->children();

        $this->filter()->applyOper($query, [
            'and' => [
                'id between 1,3',
                'status in [active,inactive]',
                'name like a',
            ],
        ]);

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $query->pluck('name')->all());
    }

    public function testApplyOrderingUsesDynamicOrderByForRelationQueries(): void
    {
        $query = RelationParentItem::findOrFail(1)->children();

        $this->filter()->applyOrdering($query, [
            ['name' => 'desc'],
        ]);

        $this->assertSame(['Gamma', 'Beta', 'Alpha', null], $query->pluck('name')->all());
    }

    private function filter(): RelationQueryFilter
    {
        return new RelationQueryFilter();
    }
}
