<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\ExportCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ExportItem;

final class ExportCoordinatorTest extends TestCase
{
    public function testExtractDataSupportsPaginatorAndArrayShapes(): void
    {
        $coordinator = $this->coordinator([]);
        $paginator = new LengthAwarePaginator(
            [['id' => 1], ['id' => 2]],
            2,
            10,
            1
        );

        $this->assertSame([['id' => 1], ['id' => 2]], $coordinator->extractData($paginator));
        $this->assertSame([['id' => 3]], $coordinator->extractData(['data' => [['id' => 3]]]));
        $this->assertSame([['id' => 4]], $coordinator->extractData([['id' => 4]]));
        $this->assertSame([], $coordinator->extractData((object)['id' => 5]));
    }

    public function testResolveColumnsHonorsColumnsSelectAndFillableFallback(): void
    {
        $coordinator = $this->coordinator([]);

        $this->assertSame(['name', 'email'], $coordinator->resolveColumns(['columns' => ' name, email ,']));
        $this->assertSame(['id', 'name'], $coordinator->resolveColumns(['select' => ['id', 'name']]));
        $this->assertSame(['id', 'name', 'email'], $coordinator->resolveColumns(['select' => '*']));
        $this->assertSame(['id', 'name', 'email'], $coordinator->resolveColumns(['select' => ['*']]));
        $this->assertSame(['id', 'name', 'email'], $coordinator->resolveColumns(['select' => []]));
    }

    public function testPayloadUsesListCallbackAndResolvedColumns(): void
    {
        $coordinator = $this->coordinator(['data' => [
            ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test'],
        ]]);

        $payload = $coordinator->payload(['columns' => ['id', 'email']]);

        $this->assertSame([
            'data' => [
                ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test'],
            ],
            'columns' => ['id', 'email'],
        ], $payload);
    }

    private function coordinator(mixed $listResult): ExportCoordinator
    {
        return new ExportCoordinator(
            new ExportItem(),
            fn ($params) => $listResult
        );
    }
}
