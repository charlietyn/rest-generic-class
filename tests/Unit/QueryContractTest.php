<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Core\Services\Support\RelationReadCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\BootsContractDatabase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ContractUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Query parameters of a list request: a request the library
 * cannot apply as written is a 400, never a 500 and never a 200 that ignored part of it.
 */
final class QueryContractTest extends TestCase
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

    private function assertBadRequest(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a 400: {$message}");
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function relationReader(): RelationReadCoordinator
    {
        return new RelationReadCoordinator(
            fn ($name) => ['relationship' => 'children', 'relatedModel' => ContractUser::class],
            fn ($config, $id) => ContractUser::findOrFail($id), new RelationQueryFilter(),
            fn (...$args) => [], fn () => 15
        );
    }

    // -------------------------------------------------------------------------
    // invalid JSON (KI-OPER-INVALID-JSON)
    // -------------------------------------------------------------------------

    public static function invalidJson(): array
    {
        return [
            'oper' => ['oper', '{"and":["id|=|1"'],
            'attr' => ['attr', '{"id":'],
            'orderby' => ['orderby', '[{"id":"desc"'],
            'pagination' => ['pagination', '{"page":1,'],
        ];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsABadRequest(string $param, string $value): void
    {
        $this->assertBadRequest(
            fn () => (new BaseService(ContractUser::class))->list_all([$param => $value]),
            "The {$param} parameter is not valid JSON."
        );
    }

    public static function invalidRelationEndpointJson(): array
    {
        return [
            'oper' => ['oper', '{bad'],
            'eq' => ['eq', '{"id":'],
            'attr' => ['attr', '{"id":'],
            'orderby' => ['orderby', '[{"id"'],
            'pagination' => ['pagination', '{"page"'],
        ];
    }

    #[DataProvider('invalidRelationEndpointJson')]
    public function testInvalidJsonIsABadRequestOnARelationEndpoint(string $param, string $value): void
    {
        $this->assertBadRequest(
            fn () => $this->relationReader()->list(Request::create('/', 'GET', [$param => $value]), 2),
            "The {$param} parameter is not valid JSON."
        );
    }

    public function testValidJsonStringsKeepWorking(): void
    {
        $service = new BaseService(ContractUser::class);

        $this->assertSame([3], $this->ids($service->list_all(['oper' => '{"and":["id|=|3"]}'])));
        $this->assertSame([3, 4], $this->ids($service->list_all(['attr' => '{"father_id":2}'])));
        $this->assertSame([5, 4, 3, 2, 1], $this->ids($service->list_all(['orderby' => '[{"id":"desc"}]'])));
        $this->assertSame(2, $service->list_all(['pagination' => '{"page":1,"pageSize":2}'])->count());
    }

    // -------------------------------------------------------------------------
    // unknown column (KI-UNKNOWN-COLUMN-500)
    // -------------------------------------------------------------------------

    public static function unknownColumns(): array
    {
        return [
            'oper' => [['oper' => ['and' => ['nope|=|1']]], "Column 'nope' does not exist on contract_users."],
            'oper with an SQL fragment' => [['oper' => ['and' => ['name) OR (1=1|=|x']]], "Column 'name) OR (1=1' does not exist on contract_users."],
            'oper on another table' => [['oper' => ['and' => ['contract_devices.platform|=|ios']]], "Column 'contract_devices.platform' does not exist on contract_users."],
            'oper in a nested group' => [['oper' => ['or' => ['and' => ['nope|=|1']]]], "Column 'nope' does not exist on contract_users."],
            'relation filter' => [['oper' => ['devices' => ['and' => ['nope|=|1']]]], "Column 'nope' does not exist on contract_devices."],
            'attr' => [['attr' => ['nope' => 1]], "Column 'nope' does not exist on contract_users."],
            'select' => [['select' => ['id', 'nope']], "Column 'nope' does not exist on contract_users."],
            'select with an expression' => [['select' => ['id', 'count(*)']], "Column 'count(*)' does not exist on contract_users."],
            'select an alias of an unknown column' => [['select' => ['nope as label']], "Column 'nope' does not exist on contract_users."],
            'orderby' => [['orderby' => [['nope' => 'asc']]], "Column 'nope' does not exist on contract_users."],
            'orderby with an SQL fragment' => [['orderby' => [['id; DELETE FROM users' => 'asc']]], "Column 'id; DELETE FROM users' does not exist on contract_users."],
            'orderby through a relation' => [['orderby' => [['father.nope' => 'asc']]], "Column 'nope' does not exist on contract_users."],
            'orderby a literal table.column' => [['orderby' => [['contract_devices.platform' => 'asc']]], "Column 'contract_devices.platform' does not exist on contract_users."],
        ];
    }

    #[DataProvider('unknownColumns')]
    public function testUnknownColumnIsABadRequest(array $params, string $message): void
    {
        $this->assertBadRequest(fn () => (new BaseService(ContractUser::class))->list_all($params), $message);
    }

    public function testUnknownColumnIsABadRequestOnShow(): void
    {
        $this->assertBadRequest(
            fn () => (new BaseService(ContractUser::class))->show(['select' => ['id', 'nope']], 3),
            "Column 'nope' does not exist on contract_users."
        );
    }

    public function testUnknownColumnIsABadRequestOnARelationEndpoint(): void
    {
        $this->assertBadRequest(
            fn () => $this->relationReader()->list(Request::create('/', 'GET', ['oper' => '{"and":["nope = 1"]}']), 2),
            "Column 'nope' does not exist on contract_users."
        );
    }

    public function testUnknownSelectColumnIsABadRequestOnARelationEndpoint(): void
    {
        $this->assertBadRequest(
            fn () => $this->relationReader()->list(Request::create('/', 'GET', ['select' => '["id","nope"]', 'pagination' => '{"page":1}']), 2),
            "Column 'nope' does not exist on contract_users."
        );
        $this->assertBadRequest(
            fn () => $this->relationReader()->show(Request::create('/', 'GET', ['select' => '["id","nope"]']), 2, 3),
            "Column 'nope' does not exist on contract_users."
        );
    }

    public function testKnownColumnsInEveryFormKeepWorking(): void
    {
        $service = new BaseService(ContractUser::class);

        $this->assertSame([3], $this->ids($service->list_all(['oper' => ['and' => ['contract_users.name|=|Vet']]])));
        $this->assertSame([['label' => 'Vet']], $service->list_all(['select' => ['name as label'], 'oper' => ['and' => ['id|=|3']]])['data']);
        $this->assertSame([3], $this->ids($service->list_all(['select' => ['contract_users.*'], 'oper' => ['and' => ['id|=|3']]])));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($service->list_all(['orderby' => [['contract_users.id' => 'asc']]])));
        $this->assertSame([2], $this->ids($service->list_all(['oper' => ['and' => ['NAME|=|Staff']]])));
    }

    public function testUnknownColumnsReachTheDatabaseWithoutStrictValidation(): void
    {
        config()->set('rest-generic-class.filtering.strict_column_validation', false);

        $this->expectException(\Illuminate\Database\QueryException::class);

        (new BaseService(ContractUser::class))->list_all(['oper' => ['and' => ['nope|=|1']]]);
    }

    // -------------------------------------------------------------------------
    // regexp by driver (KI-REGEXP-POSTGRES)
    // -------------------------------------------------------------------------

    /** SQL of an oper on a connection of `$driver` (never connected: toSql() only). */
    private function operSql(string $driver, array $oper): string
    {
        // The column listing would open the connection; the syntax check is enough here.
        config()->set('rest-generic-class.filtering.strict_column_validation', false);
        $this->db->addConnection(['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'rgc_never_connected',
            'username' => 'x', 'password' => 'x', 'prefix' => ''], $driver);

        $host = new class {
            use \Ronu\RestGenericClass\Core\Traits\HasDynamicFilter;

            public function apply($query, array $params)
            {
                return $this->applyFilters($query, $params, 'and', null);
            }
        };

        return $host->apply(ContractUser::on($driver), $oper)->toSql();
    }

    public function testRegexpIsTranslatedToTildeOnPostgres(): void
    {
        $this->assertStringContainsString('"contract_users"."name" ~ ?', $this->operSql('pgsql', ['and' => ['name|regexp|^B']]));
        $this->assertStringContainsString('"contract_users"."name" !~ ?', $this->operSql('pgsql', ['and' => ['name|not regexp|^B']]));
    }

    public function testRegexpStaysRegexpOnMysql(): void
    {
        $this->assertStringContainsString('`contract_users`.`name` regexp ?', $this->operSql('mysql', ['and' => ['name|regexp|^B']]));
        $this->assertStringContainsString('`contract_users`.`name` not regexp ?', $this->operSql('mysql', ['or' => ['name|not regexp|^B']]));
    }

    public static function driversWithoutRegexp(): array
    {
        return ['sqlite' => ['sqlite'], 'sqlsrv' => ['sqlsrv']];
    }

    #[DataProvider('driversWithoutRegexp')]
    public function testRegexpIsABadRequestOnDriversWithoutIt(string $driver): void
    {
        $this->assertBadRequest(
            fn () => $driver === 'sqlite'
                ? (new BaseService(ContractUser::class))->list_all(['oper' => ['and' => ['name|regexp|^B']]])
                : $this->operSql($driver, ['and' => ['name|not regexp|^B']]),
            "The regexp operator is not supported on the {$driver} driver."
        );
    }

    public function testRegexpIsForwardedVerbatimWithoutTranslation(): void
    {
        config()->set('rest-generic-class.filtering.translate_regexp', false);

        $this->assertStringContainsString('"contract_users"."name" regexp ?', $this->operSql('pgsql', ['and' => ['name|regexp|^B']]));
    }

    // -------------------------------------------------------------------------
    // exists / not exists are not operators (KI-EXISTS-OPERATOR)
    // -------------------------------------------------------------------------

    public static function retiredOperators(): array
    {
        return ['exists' => ['exists'], 'not exists' => ['not exists'], 'notexists' => ['notexists']];
    }

    #[DataProvider('retiredOperators')]
    public function testExistsIsNotAValidOperator(string $operator): void
    {
        $this->assertBadRequest(
            fn () => (new BaseService(ContractUser::class))->list_all(['oper' => ['and' => ["name|{$operator}|x"]]]),
            "The {$operator} value is not a valid operator."
        );
    }

    public function testARelationFilterStillAnswersHasRelatedRows(): void
    {
        $result = (new BaseService(ContractUser::class))->list_all(['oper' => ['devices' => ['and' => ['id|>|0']]]]);

        $this->assertSame([3, 4], $this->ids($result));
    }

    // -------------------------------------------------------------------------
    // max_depth counts nested logical groups (KI-DEPTH-NOT-ENFORCED)
    // -------------------------------------------------------------------------

    /** `$levels` nested `and` groups around one condition: the root counts as level 1. */
    private static function nestedGroups(int $levels, array $leaf = ['id|>|0']): array
    {
        $node = $leaf;
        for ($i = 0; $i < $levels; $i++) {
            $node = ['and' => $node];
        }
        return $node;
    }

    public function testFiveNestedGroupsAreAllowed(): void
    {
        $result = (new BaseService(ContractUser::class))->list_all(['oper' => self::nestedGroups(5)]);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($result));
    }

    public function testSixNestedGroupsExceedTheMaximumDepth(): void
    {
        $this->assertBadRequest(
            fn () => (new BaseService(ContractUser::class))->list_all(['oper' => self::nestedGroups(6)]),
            'Maximum nesting depth (5) exceeded.'
        );
    }

    public function testRelationHopsAndGroupsShareTheDepth(): void
    {
        $service = new BaseService(ContractUser::class);

        $this->assertSame([3, 4], $this->ids($service->list_all(['oper' => ['devices' => self::nestedGroups(4)]])));
        $this->assertBadRequest(
            fn () => $service->list_all(['oper' => ['devices' => self::nestedGroups(5)]]),
            'Maximum nesting depth (5) exceeded.'
        );
    }

    public function testInvalidJsonCanBeIgnoredByConfiguration(): void
    {
        config()->set('rest-generic-class.filtering.reject_invalid_json', false);

        $result = (new BaseService(ContractUser::class))->list_all(['oper' => '{"and":["id|=|1"']);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($result));
    }
}
