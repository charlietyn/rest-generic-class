<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\PaginationCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\PaginationItem;

final class PaginationCoordinatorTest extends TestCase
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
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('pagination_items', function ($table) {
                $table->increments('id');
                $table->string('name');
            });

            PaginationItem::insert([
                ['id' => 1, 'name' => 'Acme'],
                ['id' => 2, 'name' => 'Beta'],
                ['id' => 3, 'name' => 'Gamma'],
            ]);

            self::$booted = true;
        }
    }

    public function testLengthAwarePaginationUsesRequestedPageAndPageSize(): void
    {
        $result = $this->coordinator()->process(
            ['pagination' => ['page' => 2, 'pageSize' => 1]],
            PaginationItem::query()->orderBy('id')
        );

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->currentPage());
        $this->assertSame(1, $result->perPage());
        $this->assertSame([2], collect($result->items())->pluck('id')->all());
    }

    public function testLengthAwarePaginationAcceptsLowercasePageSize(): void
    {
        $result = $this->coordinator()->process(
            ['pagination' => ['page' => 1, 'pagesize' => 2]],
            PaginationItem::query()->orderBy('id')
        );

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->perPage());
        $this->assertSame([1, 2], collect($result->items())->pluck('id')->all());
    }

    public function testCursorPaginationIsReturnedWhenInfinityIsTrue(): void
    {
        $result = $this->coordinator()->process(
            ['pagination' => ['infinity' => true, 'pagesize' => 2]],
            PaginationItem::query()->orderBy('id')
        );

        $this->assertInstanceOf(CursorPaginator::class, $result);
        $this->assertSame(2, $result->perPage());
        $this->assertSame([1, 2], collect($result->items())->pluck('id')->all());
    }

    private function coordinator(): PaginationCoordinator
    {
        return new PaginationCoordinator(new PaginationItem());
    }
}
