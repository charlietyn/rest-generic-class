<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\OperFilterPipeline;
use Ronu\RestGenericClass\Core\Services\Support\RelationResolver;
use Ronu\RestGenericClass\Core\Traits\HasDynamicFilter;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OperFilterPipelineTest extends TestCase
{
    private static bool $booted = false;
    private int $depth = 0;
    private int $conditionCount = 0;

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

            self::$booted = true;
        }

        $this->depth = 0;
        $this->conditionCount = 0;

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'rest-generic-class' => [
                'filtering' => [
                    'max_depth' => 5,
                    'max_conditions' => 100,
                    'strict_relations' => true,
                ],
            ],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('config');
    }

    protected function tearDown(): void
    {
        if (Container::getInstance()->bound('config')) {
            config()->set('rest-generic-class.filtering.max_depth', 5);
            config()->set('rest-generic-class.filtering.max_conditions', 100);
            config()->set('rest-generic-class.filtering.strict_relations', true);
        }

        Facade::clearResolvedInstance('config');

        parent::tearDown();
    }

    public function testNormalizeKeepsLegacyOperShape(): void
    {
        $pipeline = $this->pipeline();

        $this->assertSame(['and' => ['name|=|Acme']], $pipeline->normalize(['name|=|Acme']));
        $this->assertSame(['and' => ['name|=|Acme']], $pipeline->normalize('{"and":["name|=|Acme"]}'));
    }

    public function testApplyCombinesBaseFiltersAndRelationWhereHas(): void
    {
        $query = $this->pipeline()->apply(
            OrderTestClient::query(),
            [
                'and' => ['name|=|Acme'],
                'user' => ['and' => ['name|=|Zara']],
            ],
            'and',
            OrderTestClient::class,
            OrderTestClient::class
        );

        $sql = $query->toSql();

        $this->assertStringContainsString('"clients"."name" = ?', $sql);
        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('"users"."name" = ?', $sql);
        $this->assertSame(2, $this->conditionCount);
        $this->assertSame(0, $this->depth);
    }

    public function testMaximumConditionsIsEnforced(): void
    {
        config()->set('rest-generic-class.filtering.max_conditions', 1);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Maximum conditions (1) exceeded.');

        $this->pipeline()->apply(
            OrderTestClient::query(),
            ['and' => ['name|=|Acme', 'id|=|1']],
            'and',
            OrderTestClient::class,
            OrderTestClient::class
        );
    }

    public function testMaximumDepthIsEnforcedForNestedRelationFilters(): void
    {
        config()->set('rest-generic-class.filtering.max_depth', 1);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Maximum nesting depth (1) exceeded.');

        $this->pipeline()->apply(
            OrderTestClient::query(),
            ['user' => ['and' => ['name|=|Zara']]],
            'and',
            OrderTestClient::class,
            OrderTestClient::class
        );
    }

    private function pipeline(): OperFilterPipeline
    {
        $filterHost = new class {
            use HasDynamicFilter;

            public function apply($query, array $params, string $condition, $model)
            {
                return $this->applyFilters($query, $params, $condition, $model);
            }
        };

        return new OperFilterPipeline(
            new RelationResolver(),
            fn ($query, array $params, string $condition, $model) => $filterHost->apply($query, $params, $condition, $model),
            fn (): int => $this->depth,
            fn (int $depth): int => $this->depth = $depth,
            fn (int $count): int => $this->conditionCount += $count,
            fn (): int => $this->conditionCount
        );
    }
}
