<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Controllers\RestController;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Core\Services\Support\RelationReadCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateCustomer;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateDeclaredCustomer;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateImplicitCustomer;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateDenied;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateNote;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateOrder;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\AggregateVisibleCustomer;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Fixtures/AggregationModels.php';

/** The same behavior suite can run against dedicated MySQL/PostgreSQL test databases. */
final class AggregationTest extends TestCase
{
    private Capsule $db;
    private Container $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private mixed $previousFacade;
    private array $tables = ['agg_notes', 'agg_customer_tag', 'agg_tags', 'agg_lines', 'agg_orders', 'agg_customers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new ConfigRepository([
            'cache' => ['serializable_classes' => false],
            'rest-generic-class' => [
                'cache' => ['enabled' => false, 'cacheable_methods' => ['list_all']],
                'filtering' => ['max_depth' => 5, 'max_conditions' => 100, 'strict_relations' => true],
            ],
        ]));
        $cache = new Repository(new ArrayStore());
        $container->instance('cache', new class($cache) {
            public function __construct(private Repository $cache) {}
            public function store($name = null): Repository { return $this->cache; }
        });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->db = new Capsule($container);
        $driver = getenv('RGC_AGGREGATE_DRIVER') ?: 'sqlite';
        $database = getenv('RGC_AGGREGATE_DATABASE') ?: 'rgc_aggregates_test';
        if ($driver !== 'sqlite' && !str_ends_with($database, '_test')) {
            throw new \RuntimeException('Aggregation integration database must end in _test.');
        }
        $this->db->addConnection($driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']
            : ['driver' => $driver, 'host' => getenv('RGC_AGGREGATE_HOST') ?: '127.0.0.1',
                'port' => getenv('RGC_AGGREGATE_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306),
                'database' => $database, 'username' => getenv('RGC_AGGREGATE_USER') ?: 'rgc',
                'password' => getenv('RGC_AGGREGATE_PASSWORD') ?: '', 'prefix' => '']);
        $this->db->setEventDispatcher(new Dispatcher($container));
        $this->db->bootEloquent();
        $this->schema();
        $this->seed();
        $this->db->getConnection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->db->getConnection()->getSchemaBuilder()->dropIfExists($table);
        }
        $this->db->getConnection()->disconnect();
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        parent::tearDown();
    }

    private function schema(): void
    {
        $schema = $this->db->getConnection()->getSchemaBuilder();
        foreach ($this->tables as $table) { $schema->dropIfExists($table); }
        $schema->create('agg_customers', function ($t) {
            $t->integer('id')->primary(); $t->string('code', 64)->unique(); $t->string('name');
            $t->integer('tenant_id')->default(1); $t->integer('manager_id')->nullable(); $t->softDeletes();
        });
        $schema->create('agg_orders', function ($t) {
            $t->integer('id')->primary(); $t->string('customer_code', 64); $t->decimal('amount', 18, 4)->nullable();
            $t->string('status'); $t->integer('tenant_id')->default(1); $t->softDeletes();
        });
        $schema->create('agg_lines', function ($t) {
            $t->integer('id')->primary(); $t->integer('order_id'); $t->decimal('amount', 18, 4)->nullable();
        });
        $schema->create('agg_tags', function ($t) {
            $t->integer('id')->primary(); $t->decimal('amount', 18, 4);
        });
        $schema->create('agg_customer_tag', function ($t) {
            $t->integer('customer_id'); $t->integer('tag_id'); $t->integer('active');
        });
        $schema->create('agg_notes', function ($t) {
            $t->integer('id')->primary(); $t->integer('notable_id'); $t->string('notable_type'); $t->decimal('amount', 18, 4);
        });
    }

    private function seed(): void
    {
        $c = $this->db->getConnection();
        $c->table('agg_customers')->insert([
            ['id' => 1, 'code' => 'A', 'name' => 'Alice', 'tenant_id' => 1, 'manager_id' => null, 'deleted_at' => null],
            ['id' => 2, 'code' => 'B', 'name' => 'Bob', 'tenant_id' => 1, 'manager_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'code' => 'C', 'name' => 'Carol', 'tenant_id' => 1, 'manager_id' => 1, 'deleted_at' => null],
            ['id' => 4, 'code' => 'D', 'name' => 'Other tenant', 'tenant_id' => 2, 'manager_id' => 1, 'deleted_at' => null],
            ['id' => 5, 'code' => 'E', 'name' => 'Deleted', 'tenant_id' => 1, 'manager_id' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
        foreach ([[1, 'A', 10, 'paid', 1, null], [2, 'A', 30, 'paid', 1, null], [3, 'A', 100, 'pending', 1, null],
            [4, 'B', null, 'paid', 1, null], [5, 'A', 900, 'paid', 2, null], [6, 'A', 800, 'paid', 1, '2026-01-01 00:00:00']] as $row) {
            $c->table('agg_orders')->insert(array_combine(['id', 'customer_code', 'amount', 'status', 'tenant_id', 'deleted_at'], $row));
        }
        $c->table('agg_lines')->insert([['id' => 1, 'order_id' => 1, 'amount' => 2], ['id' => 2, 'order_id' => 1, 'amount' => 4], ['id' => 3, 'order_id' => 2, 'amount' => 12]]);
        $c->table('agg_tags')->insert([['id' => 1, 'amount' => 3], ['id' => 2, 'amount' => 5], ['id' => 3, 'amount' => 7]]);
        $c->table('agg_customer_tag')->insert([['customer_id' => 1, 'tag_id' => 1, 'active' => 1], ['customer_id' => 1, 'tag_id' => 2, 'active' => 1], ['customer_id' => 1, 'tag_id' => 3, 'active' => 0]]);
        $c->table('agg_notes')->insert([['id' => 1, 'notable_id' => 1, 'notable_type' => AggregateCustomer::class, 'amount' => 6], ['id' => 2, 'notable_id' => 1, 'notable_type' => AggregateOrder::class, 'amount' => 50]]);
    }

    private function metric(string $function = 'count', string $column = '*', string $alias = 'metric'): array
    {
        return ['function' => $function, 'column' => $column, 'as' => $alias];
    }

    private function relationMetric(string $relation = 'orders', string $function = 'count', string $column = '*', string $alias = 'metric'): array
    {
        return ['relation' => $relation] + $this->metric($function, $column, $alias);
    }

    private function dataQueries(): array
    {
        return array_values(array_filter($this->db->getConnection()->getQueryLog(), fn ($q) => (bool) preg_match('/from ["`]?agg_/', $q['query'])));
    }

    public function testGlobalMetricsPreserveScopesNullsAndUseOneQueryPerMetric(): void
    {
        $retrieved = 0;
        AggregateOrder::retrieved(function () use (&$retrieved) { ++$retrieved; });
        $params = ['oper' => ['and' => ['status|=|paid']], 'aggregate' => [
            $this->metric('count', '*', 'rows_count'), $this->metric('count', 'amount', 'nonnull'),
            $this->metric('sum', 'amount', 'total'), $this->metric('avg', 'amount', 'average'),
            $this->metric('min', 'amount', 'lowest'), $this->metric('max', 'amount', 'highest'),
        ]];
        $result = (new BaseService(AggregateOrder::class))->list_all($params)['data'];
        $this->assertSame(3, $result['rows_count']);
        $this->assertSame(2, $result['nonnull']);
        $this->assertIsString($result['total']); $this->assertEquals(40, $result['total']);
        $this->assertIsString($result['average']); $this->assertEquals(20, $result['average']);
        $this->assertEquals(10, $result['lowest']); $this->assertEquals(30, $result['highest']);
        $this->assertSame(0, $retrieved);
        $this->assertCount(6, $this->dataQueries());
        $this->assertSame($result, (new BaseService(AggregateOrder::class))->list_all($params, false));
    }

    public function testEmptyAndAllNullResults(): void
    {
        foreach (['B', 'missing'] as $code) {
            $result = (new BaseService(AggregateOrder::class))->list_all(['attr' => ['customer_code' => $code], 'aggregate' => [
                $this->metric('sum', 'amount', 'total'), $this->metric('avg', 'amount', 'average'), $this->metric('count', 'amount', 'nonnull'),
            ]], false);
            $this->assertSame(['total' => '0', 'average' => null, 'nonnull' => 0], $result);
        }
    }

    public function testNestedWhereHasFiltersAndJsonHttpParameters(): void
    {
        $request = Request::create('/', 'GET', ['aggregate' => json_encode([$this->metric()]), 'oper' => json_encode(['orders.lines' => ['and' => ['amount|>|3']]])]);
        $params = (new RestController())->process_request($request);
        $this->assertSame(['data' => ['metric' => 1]], (new BaseService(AggregateCustomer::class))->list_all($params));
    }

    public function testHttpJsonEqualityAliasesAreNormalizedBeforeMerging(): void
    {
        $request = Request::create('/', 'GET', ['aggregate' => json_encode([$this->metric()]),
            'attr' => '{"status":"paid"}', 'eq' => '{"customer_code":"A"}']);
        $params = (new RestController())->process_request($request);
        $this->assertSame(2, (new BaseService(AggregateOrder::class))->list_all($params)['data']['metric']);
    }

    public function testDecimalsAndNegativeValuesAreNotRoundedByThePackage(): void
    {
        $this->db->getConnection()->table('agg_orders')->where('id', 1)->update(['amount' => '0.1234']);
        $this->db->getConnection()->table('agg_orders')->where('id', 2)->update(['amount' => '-0.0234']);
        $result = (new BaseService(AggregateOrder::class))->list_all(['attr' => ['id' => [1, 2]],
            'aggregate' => [$this->metric('sum', 'amount'), $this->metric('avg', 'amount', 'average')]], false);
        $this->assertIsString($result['metric']);
        $this->assertEqualsWithDelta(0.1, (float) $result['metric'], 0.000001);
        $this->assertEqualsWithDelta(0.05, (float) $result['average'], 0.000001);
    }

    public function testRelationMetricsHaveOwnFiltersDoNotLoadChildrenAndDoNotDuplicateRows(): void
    {
        $spec = $this->relationMetric('orders', 'sum', 'amount', 'paid_total') + ['oper' => ['and' => ['status|=|paid']]];
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['select' => ['id', 'name'], 'with_aggregates' => [
            $spec, $this->relationMetric('orders', 'count', '*', 'orders_count'), $this->relationMetric('tags', 'sum', 'amount', 'tags_total'),
        ], 'orderby' => [['id' => 'asc']]], false);
        $this->assertCount(3, $rows);
        $this->assertEquals(40, $rows[0]['paid_total']); $this->assertSame(3, $rows[0]['orders_count']);
        $this->assertEquals(8, $rows[0]['tags_total']);
        $this->assertSame('0', $rows[2]['paid_total']); $this->assertSame(0, $rows[2]['orders_count']);
        $this->assertArrayNotHasKey('orders', $rows[0]);
        $this->assertCount(1, $this->dataQueries());
    }

    public function testMainRelationFilterDoesNotImplicitlyFilterMetric(): void
    {
        $rows = (new BaseService(AggregateCustomer::class))->list_all([
            'oper' => ['orders' => ['and' => ['status|=|paid']]], '_nested' => true,
            'with_aggregates' => [$this->relationMetric()], 'orderby' => [['id' => 'asc']],
        ], false);
        $this->assertCount(2, $rows); $this->assertSame(3, $rows[0]['metric']);
    }

    public function testMultipleAliasesAndNestedFiltersInsideMetrics(): void
    {
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['attr' => ['id' => 1], 'with_aggregates' => [
            $this->relationMetric('orders', 'count', '*', 'paid_count') + ['attr' => ['status' => 'paid']],
            $this->relationMetric('orders', 'count', '*', 'pending_count') + ['attr' => ['status' => 'pending']],
            $this->relationMetric('orders', 'count', '*', 'with_lines') + ['oper' => ['lines' => ['and' => ['amount|>|3']]]],
        ]], false);
        $this->assertSame(2, $rows[0]['paid_count']);
        $this->assertSame(1, $rows[0]['pending_count']);
        $this->assertSame(2, $rows[0]['with_lines']);
    }

    public function testNativeRelationTypesAndCustomKeys(): void
    {
        $specs = [];
        foreach (['orders', 'firstOrder', 'lines', 'firstLine', 'tags', 'reports', 'notes'] as $relation) {
            $specs[] = $this->relationMetric($relation, 'count', '*', $relation.'_metric');
        }
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['attr' => ['id' => 1], 'with_aggregates' => $specs], false);
        foreach (['orders' => 3, 'firstOrder' => 3, 'lines' => 3, 'firstLine' => 3, 'tags' => 2, 'reports' => 2, 'notes' => 1] as $relation => $count) {
            $this->assertSame($count, $rows[0][$relation.'_metric'], $relation);
        }
        $order = (new BaseService(AggregateOrder::class))->list_all(['attr' => ['id' => 1], 'with_aggregates' => [$this->relationMetric('customer')]], false);
        $this->assertSame(1, $order[0]['metric']);
    }

    public function testSelfRelationFiltersUseTheEloquentAlias(): void
    {
        $spec = $this->relationMetric('reports') + ['attr' => ['name' => 'Bob'], 'oper' => ['and' => ['id|>|1']]];
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['attr' => ['id' => 1], 'with_aggregates' => [$spec]], false);
        $this->assertSame(1, $rows[0]['metric']);
    }

    public function testVisibleListsDoNotHideExplicitlyRequestedMetrics(): void
    {
        $service = new BaseService(AggregateVisibleCustomer::class);
        $rows = $service->process_query(['with_aggregates' => [$this->relationMetric()]], $service->modelClass->newQuery())->get()->toArray();
        $this->assertArrayHasKey('metric', $rows[0]);
        $this->assertArrayNotHasKey('name', $rows[0]);
    }

    public function testNamedThroughRelationPreservesSoftDeleteConstraints(): void
    {
        $this->db->getConnection()->table('agg_lines')->insert(['id' => 4, 'order_id' => 6, 'amount' => 999]);
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['attr' => ['id' => 1],
            'with_aggregates' => [$this->relationMetric('lines', 'sum', 'amount')]], false);
        $this->assertEquals(18, $rows[0]['metric']);
    }

    public function testCountColumnAndAverageOnRelations(): void
    {
        $rows = (new BaseService(AggregateCustomer::class))->list_all(['with_aggregates' => [
            $this->relationMetric('orders', 'count', 'amount', 'nonnull'), $this->relationMetric('orders', 'avg', 'amount', 'average'),
        ], 'orderby' => [['id' => 'asc']]], false);
        $this->assertSame(3, $rows[0]['nonnull']);
        $this->assertEqualsWithDelta(140 / 3, (float) $rows[0]['average'], 0.0001);
        $this->assertSame(0, $rows[1]['nonnull']); $this->assertNull($rows[1]['average']);
    }

    public function testOrderingAndPaginationKeepRecordTotals(): void
    {
        $params = ['with_aggregates' => [$this->relationMetric()], 'orderby' => [['metric' => 'desc'], ['id' => 'asc']], 'pagination' => ['pageSize' => 1, 'page' => 1]];
        $page = (new BaseService(AggregateCustomer::class))->list_all($params);
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(3, $page->total()); $this->assertSame(3, $page->items()[0]->metric);
        $params['pagination']['page'] = 99;
        $this->assertCount(0, (new BaseService(AggregateCustomer::class))->list_all($params)->items());
    }

    public function testCursorWithBaseColumnOrdering(): void
    {
        $page = (new BaseService(AggregateCustomer::class))->list_all(['with_aggregates' => [$this->relationMetric()],
            'orderby' => [['id' => 'asc']], 'pagination' => ['infinity' => true, 'pageSize' => 1]]);
        $this->assertInstanceOf(CursorPaginator::class, $page);
        $this->assertSame(3, $page->items()[0]->metric); $this->assertNotNull($page->nextCursor());
    }

    public static function directQueryMethods(): array
    {
        return [['process_query'], ['process_all']];
    }

    #[DataProvider('directQueryMethods')]
    public function testDirectBuildersApplyJsonEqualityFiltersWithMetrics(string $method): void
    {
        $service = new BaseService(AggregateCustomer::class);
        $result = $service->{$method}([
            'eq' => json_encode(['id' => 2, 'name' => 'Bob']),
            'attr' => json_encode(['name' => 'Carol']),
            'with_aggregates' => json_encode([$this->relationMetric()]),
        ], AggregateCustomer::query())->get();
        // attr overrides duplicate eq fields, while the remaining eq fields still apply.
        $this->assertCount(0, $result);

        $result = $service->{$method}([
            'eq' => json_encode(['id' => 2]),
            'with_aggregates' => json_encode([$this->relationMetric()]),
        ], AggregateCustomer::query())->get();
        $this->assertSame([2], $result->pluck('id')->all());
        $this->assertSame(1, $result->first()->metric);
    }

    public static function relationStrictness(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('relationStrictness')]
    public function testMetricsRequireExplicitRelationPermissionRegardlessOfLegacyStrictness(bool $strict): void
    {
        config(['rest-generic-class.filtering.strict_relations' => $strict]);
        try {
            (new BaseService(AggregateImplicitCustomer::class))->list_all([
                'with_aggregates' => [$this->relationMetric()],
            ]);
            $this->fail('Expected an explicit relation authorization error');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('with_aggregates.0.relation', $e->getMessage());
            $this->assertCount(0, $this->dataQueries());
        }
    }

    public function testRelationPermissionCanBeDeclaredByContractWithoutConstant(): void
    {
        $rows = (new BaseService(AggregateDeclaredCustomer::class))->list_all([
            'attr' => ['id' => 1], 'with_aggregates' => [$this->relationMetric()],
        ], false);
        $this->assertSame(3, $rows[0]['metric']);
    }

    private function relationReader(string $relationship = 'orders'): RelationReadCoordinator
    {
        return new RelationReadCoordinator(
            fn ($name) => ['relationship' => $relationship, 'relatedModel' => AggregateOrder::class],
            fn ($config, $id) => AggregateCustomer::findOrFail($id), new RelationQueryFilter(),
            fn (...$args) => [], fn () => 15
        );
    }

    public function testRelationEndpointGlobalsPreserveParentPivotAndSpaceFilters(): void
    {
        $request = Request::create('/', 'GET', ['aggregate' => [$this->metric('sum', 'amount')], 'oper' => ['and' => ['status = paid']]]);
        $this->assertEquals(40, $this->relationReader()->list($request, 1)['data']['metric']);
        $this->assertSame('0', $this->relationReader()->list($request, 2)['data']['metric']);
        $pivotRequest = Request::create('/', 'GET', ['aggregate' => [$this->metric('sum', 'amount')]]);
        $this->assertEquals(8, $this->relationReader('tags')->list($pivotRequest, 1)['data']['metric']);
    }

    public function testRelationEndpointPerRecordMetricsAndPagination(): void
    {
        $request = Request::create('/', 'GET', ['with_aggregates' => [$this->relationMetric('lines', 'sum', 'amount') + ['oper' => ['and' => ['amount > 2']]]],
            'eq' => ['id' => [1, 2]], 'select' => ['id'], 'orderby' => [['metric' => 'desc']], 'pagination' => ['pageSize' => 1]]);
        $result = $this->relationReader()->list($request, 1);
        $this->assertSame(2, $result->total()); $this->assertEquals(12, $result->items()[0]->metric);
        $this->assertSame(2, $result->items()[0]->id);
    }

    public function testNullAliasOrderingFollowsTheDatabaseBeforeNormalization(): void
    {
        $params = ['with_aggregates' => [$this->relationMetric('lines', 'sum', 'amount')], 'orderby' => [['metric' => 'desc']]];
        $rows = $this->relationReader()->list(Request::create('/', 'GET', $params), 1)['data'];
        $postgres = $this->db->getConnection()->getDriverName() === 'pgsql';
        $this->assertSame($postgres ? 3 : 2, $rows[0]['id']);
        $this->assertEquals($postgres ? 0 : 12, $rows[0]['metric']);
    }

    public function testRelationEndpointPreservesParentAuthorizationScope(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->relationReader()->list(Request::create('/', 'GET', ['aggregate' => [$this->metric()]]), 4);
    }

    public function testMalformedFiltersOnRelationEndpointAreNotSilentlyIgnored(): void
    {
        $this->expectException(HttpException::class);
        $this->relationReader()->list(Request::create('/', 'GET', ['aggregate' => [$this->metric()], 'oper' => '{broken']), 1);
    }

    public function testLocalCacheInvalidatesOnServiceDeleteAndRestore(): void
    {
        config()->set('rest-generic-class.cache.enabled', true);
        $params = ['aggregate' => [$this->metric()]];
        $service = new BaseService(AggregateCustomer::class);
        $this->assertSame(3, $service->list_all($params)['data']['metric']);
        $this->db->getConnection()->flushQueryLog();
        $this->assertSame(3, $service->list_all($params)['data']['metric']);
        $this->assertCount(0, $this->dataQueries());
        $service->destroy(3);
        $this->assertSame(2, $service->list_all($params)['data']['metric']);
        $service->restore(3);
        $this->assertSame(3, $service->list_all($params)['data']['metric']);
    }

    public function testDependentMetricsBypassCacheAfterChildAndPivotChanges(): void
    {
        config()->set('rest-generic-class.cache.enabled', true);
        $params = ['attr' => ['id' => 1], 'with_aggregates' => [$this->relationMetric(), $this->relationMetric('tags', 'count', '*', 'tag_count')]];
        $service = new BaseService(AggregateCustomer::class);
        $this->assertSame(3, $service->list_all($params)['data'][0]['metric']);
        AggregateOrder::findOrFail(1)->delete();
        AggregateCustomer::findOrFail(1)->tags()->detach(1);
        $row = $service->list_all($params)['data'][0];
        $this->assertSame(2, $row['metric']); $this->assertSame(1, $row['tag_count']);
        AggregateCustomer::findOrFail(1)->tags()->sync([1 => ['active' => 1]]);
        $this->assertSame(1, $service->list_all($params)['data'][0]['tag_count']);
        AggregateCustomer::findOrFail(1)->tags()->updateExistingPivot(1, ['active' => 0]);
        $this->assertSame(0, $service->list_all($params)['data'][0]['tag_count']);
        AggregateOrder::withTrashed()->findOrFail(1)->restore();
        $this->assertSame(3, $service->list_all($params)['data'][0]['metric']);
    }

    public function testGlobalRelationFiltersAlsoBypassCache(): void
    {
        config()->set('rest-generic-class.cache.enabled', true);
        $params = ['aggregate' => [$this->metric()], 'oper' => ['tags' => ['and' => ['id|=|1']]]];
        $service = new BaseService(AggregateCustomer::class);
        $this->assertSame(1, $service->list_all($params)['data']['metric']);
        AggregateCustomer::findOrFail(1)->tags()->detach(1);
        $this->assertSame(0, $service->list_all($params)['data']['metric']);
    }

    public function testMetricsLimitIsEnforced(): void
    {
        config()->set('rest-generic-class.aggregations.max_metrics', 1);
        $this->expectException(HttpException::class);
        (new BaseService(AggregateCustomer::class))->list_all(['aggregate' => [$this->metric(), $this->metric('count', '*', 'another')]]);
    }

    public function testDeepRelationFilterLimitIsEnforced(): void
    {
        $this->expectException(HttpException::class);
        (new BaseService(AggregateCustomer::class))->list_all(['aggregate' => [$this->metric()],
            'oper' => ['orders.customer.orders.customer.orders.customer' => ['and' => ['id|=|1']]]]);
    }

    public function testModelsMustOptIn(): void
    {
        $this->expectException(HttpException::class);
        (new BaseService(AggregateDenied::class))->list_all(['aggregate' => [$this->metric()]]);
    }

    public function testMorphToIsRejected(): void
    {
        $this->expectException(HttpException::class);
        (new BaseService(AggregateNote::class))->list_all(['with_aggregates' => [$this->relationMetric('notable')]]);
    }

    public function testConditionBudgetIncludesAllMetricsAndRootFilters(): void
    {
        config()->set('rest-generic-class.filtering.max_conditions', 2);
        $this->expectException(HttpException::class);
        (new BaseService(AggregateCustomer::class))->list_all(['attr' => ['id' => 1], 'with_aggregates' => [
            $this->relationMetric('orders', 'count', '*', 'first_metric') + ['oper' => ['and' => ['status|=|paid']]],
            $this->relationMetric('orders', 'count', '*', 'second_metric') + ['attr' => ['status' => 'pending']],
        ]]);
    }

    public function testMissingConfiguredColumnIsRejected(): void
    {
        $this->expectException(HttpException::class);
        (new BaseService(AggregateCustomer::class))->list_all(['aggregate' => [$this->metric('sum', 'amount')]]);
    }

    public static function invalidParameters(): array
    {
        $metric = ['function' => 'count', 'column' => '*', 'as' => 'metric'];
        $relation = ['relation' => 'orders'] + $metric;
        return [
            'null' => [['aggregate' => null]], 'empty' => [['aggregate' => []]],
            'json' => [['aggregate' => '[broken']], 'scalar' => [['aggregate' => 'true']],
            'unsupported function' => [['aggregate' => [array_replace($metric, ['function' => 'median'])]]],
            'raw column' => [['aggregate' => [array_replace($metric, ['column' => 'COUNT(*)'])]]],
            'sum wildcard' => [['aggregate' => [array_replace($metric, ['function' => 'sum'])]]],
            'raw alias' => [['aggregate' => [array_replace($metric, ['as' => 'a; drop'])]]],
            'reserved alias' => [['aggregate' => [array_replace($metric, ['as' => 'laravel_reserved_0'])]]],
            'duplicate alias' => [['aggregate' => [$metric, $metric]]],
            'case duplicate alias' => [['aggregate' => [$metric, array_replace($metric, ['as' => 'METRIC'])]]],
            'unauthorized column' => [['aggregate' => [array_replace($metric, ['column' => 'name'])]]],
            'column collision' => [['aggregate' => [array_replace($metric, ['as' => 'name'])]]],
            'accessor collision' => [['aggregate' => [array_replace($metric, ['as' => 'display_name'])]]],
            'unknown option' => [['aggregate' => [$metric + ['distinct' => true]]]],
            'pagination' => [['aggregate' => [$metric], 'pagination' => ['pageSize' => 2]]],
            'select' => [['aggregate' => [$metric], 'select' => ['id']]],
            'both modes' => [['aggregate' => [$metric], 'with_aggregates' => [$relation]]],
            'grouping' => [['aggregate' => [$metric], 'groupby' => ['name']]],
            'hierarchy' => [['with_aggregates' => [$relation], 'hierarchy' => true]],
            'deep path' => [['with_aggregates' => [array_replace($relation, ['relation' => 'orders.lines'])]]],
            'unauthorized relation' => [['with_aggregates' => [array_replace($relation, ['relation' => 'unknown'])]]],
            'unknown order' => [['with_aggregates' => [$relation], 'orderby' => [['nonexistent' => 'asc']]]],
            'invalid direction' => [['with_aggregates' => [$relation], 'orderby' => [['metric' => 'wrong']]]],
            'cursor alias' => [['with_aggregates' => [$relation], 'orderby' => [['metric' => 'desc']], 'pagination' => ['infinity' => true]]],
            'filter JSON' => [['with_aggregates' => [$relation + ['oper' => '{broken']]]],
            'filter field' => [['with_aggregates' => [$relation + ['attr' => ['sum(amount)' => 1]]]]],
            'raw oper field' => [['with_aggregates' => [$relation + ['oper' => ['and' => ['amount + 1|ilikeu|x']]]]]],
            'select alias collision' => [['with_aggregates' => [$relation], 'select' => ['id as metric']]],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejectedBeforeReadingData(array $params): void
    {
        try {
            (new BaseService(AggregateCustomer::class))->list_all($params);
            $this->fail('Expected a validation error');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertCount(0, $this->dataQueries());
        }
    }

    public static function unsupportedEndpoints(): array
    {
        return array_map(fn ($method) => [$method], ['get_one', 'show', 'showHierarchy', 'listHierarchy', 'exportExcel', 'exportPdf', 'process_query']);
    }

    #[DataProvider('unsupportedEndpoints')]
    public function testUnsupportedEndpointsRejectAggregations(string $method): void
    {
        $service = new BaseService(AggregateCustomer::class);
        $this->expectException(HttpException::class);
        $service->{$method}(['aggregate' => [$this->metric()]], $method === 'process_query' ? AggregateCustomer::query() : 1);
    }
}
