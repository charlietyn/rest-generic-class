<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\BootsContractDatabase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ContractUser;

/**
 * Relations and hierarchies, on the self-referential fixture:
 * 1 Root ── 2 Staff ──┬── 3 Vet;  5 Other.
 *                     └── 4 Tech
 */
final class RelationContractTest extends TestCase
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

    // -------------------------------------------------------------------------
    // self-referential relation filter (KI-SELF-RELATION-FILTER)
    // -------------------------------------------------------------------------

    public function testFilterThroughASelfRelationComparesTheRelatedRow(): void
    {
        $service = new BaseService(ContractUser::class);

        $this->assertSame([3, 4], $this->ids($service->list_all(['oper' => ['father' => ['and' => ['id|=|2']]]])));
        $this->assertSame([3, 4], $this->ids($service->list_all(['oper' => ['father' => ['and' => ['name|=|Staff']]]])));
    }

    public function testNestedGroupsInsideASelfRelationFilterUseTheRelatedRow(): void
    {
        $result = (new BaseService(ContractUser::class))->list_all([
            'oper' => ['father' => ['and' => ['or' => ['name|=|Root', 'name|=|Nobody']]]],
        ]);

        $this->assertSame([2], $this->ids($result));
    }

    public function testFilterThroughATwoHopSelfRelation(): void
    {
        $result = (new BaseService(ContractUser::class))->list_all([
            'oper' => ['father.father' => ['and' => ['name|=|Root']]],
        ]);

        $this->assertSame([3, 4], $this->ids($result));
    }

    // -------------------------------------------------------------------------
    // _nested=true filters the eager-loaded relation too (KI-NESTED-BELONGSTO)
    // -------------------------------------------------------------------------

    public function testNestedFilterOnABelongsToRelation(): void
    {
        $rows = (new BaseService(ContractUser::class))->list_all([
            'relations' => ['father'],
            '_nested' => true,
            'oper' => ['father' => ['and' => ['name|=|Staff']]],
        ])['data'];

        $this->assertSame([3, 4], array_column($rows, 'id'));
        $this->assertSame(['Staff', 'Staff'], array_column(array_column($rows, 'father'), 'name'));
    }

    public function testNestedFilterNarrowsTheLoadedChildren(): void
    {
        $rows = (new BaseService(ContractUser::class))->list_all([
            'relations' => ['children'],
            '_nested' => true,
            'oper' => ['children' => ['and' => ['name|=|Vet']]],
        ])['data'];

        $this->assertSame([2], array_column($rows, 'id'));
        $this->assertSame([3], array_column($rows[0]['children'], 'id'));
    }

    public function testNestedFilterOnShow(): void
    {
        $user = (new BaseService(ContractUser::class))->show([
            'relations' => ['children'],
            '_nested' => true,
            'oper' => ['children' => ['and' => ['name|=|Tech']]],
        ], 2);

        $this->assertSame([4], $user->children->pluck('id')->all());
    }

    // -------------------------------------------------------------------------
    // nodes added by a hierarchy filter_mode honour select (KI-HIERARCHY-SELECT)
    // -------------------------------------------------------------------------

    /** Every node of `$tree` as its sorted key list. */
    private function nodeKeys(array $tree): array
    {
        $keys = [];
        foreach ($tree as $node) {
            $own = array_keys($node);
            sort($own);
            $keys[$node['id']] = $own;
            $keys += $this->nodeKeys($node['children'] ?? []);
        }
        ksort($keys);
        return $keys;
    }

    public static function filterModes(): array
    {
        return [
            'with_ancestors' => ['with_ancestors', 'id|=|3', [1, 2, 3]],
            'with_descendants' => ['with_descendants', 'id|=|2', [2, 3, 4]],
            'full_branch' => ['full_branch', 'id|=|2', [1, 2, 3, 4]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filterModes')]
    public function testNodesAddedByAFilterModeHonourSelect(string $mode, string $condition, array $ids): void
    {
        $tree = (new BaseService(ContractUser::class))->list_all([
            'hierarchy' => ['filter_mode' => $mode],
            'oper' => ['and' => [$condition]],
            'select' => ['id', 'father_id'],
        ])['data'];

        $keys = $this->nodeKeys($tree);
        $this->assertSame($ids, array_keys($keys));
        $this->assertSame(array_fill_keys($ids, ['children', 'father_id', 'id']), $keys);
    }

    public function testATreeKeepsItsKeysWhenSelectOmitsThem(): void
    {
        $tree = (new BaseService(ContractUser::class))->list_all([
            'hierarchy' => ['filter_mode' => 'with_ancestors'],
            'oper' => ['and' => ['id|=|3']],
            'select' => ['name'],
        ])['data'];

        $this->assertSame([1], array_column($tree, 'id'));
        $this->assertSame('Vet', $tree[0]['children'][0]['children'][0]['name']);
        $this->assertSame([1, 2, 3], array_keys($this->nodeKeys($tree)));
        $this->assertSame(['children', 'father_id', 'id', 'name'], $this->nodeKeys($tree)[1]);
    }

    public function testShowHierarchyHonoursSelectOnEveryNode(): void
    {
        $node = (new BaseService(ContractUser::class))->showHierarchy([
            'hierarchy' => ['mode' => 'full_branch'],
            'select' => ['id', 'father_id'],
        ], 2);

        $this->assertSame([1], [$node['id']]);
        $this->assertSame(array_fill_keys([1, 2, 3, 4], ['children', 'father_id', 'id']), $this->nodeKeys([$node]));
    }

    public function testOrderByASelfRelationColumnUsesTheRelatedRow(): void
    {
        $result = (new BaseService(ContractUser::class))->list_all([
            'orderby' => [['father.name' => 'asc'], ['id' => 'asc']],
        ]);

        // fathers: 1,5 none · 2 Root · 3,4 Staff
        $this->assertSame([1, 5, 2, 3, 4], $this->ids($result));
    }
}
