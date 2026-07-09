<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\QueryBuilderPipeline;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestClient;

final class QueryBuilderPipelineTest extends TestCase
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

        self::$booted = true;
    }

    public function testProcessAppliesQueryStepsInOrder(): void
    {
        $calls = [];
        $model = new OrderTestClient();

        $pipeline = new QueryBuilderPipeline(
            $model,
            function ($query, $params) use (&$calls) {
                $calls[] = ['eq', $params];
                return $query;
            },
            function ($query, $oper, $boolean, $modelClass) use (&$calls) {
                $calls[] = ['oper', $oper, $boolean, get_class($modelClass)];
                return $query;
            },
            function ($query, $relations, $oper) use (&$calls) {
                $calls[] = ['relations', $relations, $oper];
                return $query;
            },
            function ($query, $params) use (&$calls) {
                $calls[] = ['orderby', $params];
                return $query->orderBy('id', 'desc');
            }
        );

        $query = $pipeline->process([
            'attr' => ['id' => [1, 2]],
            'oper' => '{"and":["name|=|Acme"]}',
            '_nested' => true,
            'relations' => ['user:id,name'],
            'select' => ['id', 'name'],
            'orderby' => [['id' => 'desc']],
        ], OrderTestClient::query());

        $this->assertSame('select "id", "name" from "clients" order by "id" desc', $query->toSql());
        $this->assertSame('eq', $calls[0][0]);
        $this->assertSame('oper', $calls[1][0]);
        $this->assertSame(['and' => ['name|=|Acme']], $calls[1][1]);
        $this->assertSame('relations', $calls[2][0]);
        $this->assertSame(['and' => ['name|=|Acme']], $calls[2][2]);
        $this->assertSame('orderby', $calls[3][0]);
    }

    public function testProcessAllDelegatesPaginationWhenRequested(): void
    {
        $pipeline = new QueryBuilderPipeline(
            new OrderTestClient(),
            fn ($query, $params) => $query,
            fn ($query, $oper, $boolean, $modelClass) => $query,
            fn ($query, $relations, $oper) => $query,
            fn ($query, $params) => $query
        );

        $result = $pipeline->processAll(
            ['pagination' => ['pageSize' => 10]],
            OrderTestClient::query(),
            fn ($params, $query) => ['paginated' => true, 'params' => $params]
        );

        $this->assertSame([
            'paginated' => true,
            'params' => ['pagination' => ['pageSize' => 10]],
        ], $result);
    }
}
