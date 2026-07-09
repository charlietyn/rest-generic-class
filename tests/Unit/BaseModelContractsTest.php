<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Contracts\HasRestCache;
use Ronu\RestGenericClass\Core\Contracts\HasRestFieldPermissions;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Ronu\RestGenericClass\Core\Contracts\HasRestSoftDeletes;
use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\HardItem;

final class BaseModelContractsTest extends TestCase
{
    public function testBaseModelExposesSmallRestContracts(): void
    {
        $model = new class extends BaseModel {
            public const RELATIONS = ['owner', 'items'];
            public const CACHE_INVALIDATES = [HardItem::class];

            protected ?string $softDeleteColumn = 'archived_at';

            protected array $fieldsByRole = [
                'admin' => ['status'],
                'owner' => ['private_notes'],
            ];
        };

        $admin = new class {
            public function hasRole(string $role): bool
            {
                return $role === 'admin';
            }
        };

        $this->assertInstanceOf(HasRestRelations::class, $model);
        $this->assertInstanceOf(HasRestCache::class, $model);
        $this->assertInstanceOf(HasRestSoftDeletes::class, $model);
        $this->assertInstanceOf(HasRestFieldPermissions::class, $model);

        $this->assertSame(['owner', 'items'], $model->getRestRelations());
        $this->assertSame([HardItem::class], $model->getCacheInvalidates());
        $this->assertSame('archived_at', $model->getSoftDeleteColumn());
        $this->assertSame(['private_notes'], $model->getDeniedFieldsForUser($admin));
    }
}
