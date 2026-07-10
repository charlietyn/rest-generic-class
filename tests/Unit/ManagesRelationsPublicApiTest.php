<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Traits\ManagesRelations;

final class ManagesRelationsPublicApiTest extends TestCase
{
    public function testPublicRelationEntryPointsRemainAvailable(): void
    {
        $trait = new \ReflectionClass(ManagesRelations::class);

        foreach ($this->expectedPublicMethods() as $method) {
            $this->assertTrue($trait->hasMethod($method), "{$method} is missing.");
            $this->assertTrue($trait->getMethod($method)->isPublic(), "{$method} is not public.");
        }
    }

    private function expectedPublicMethods(): array
    {
        return [
            'listRelation',
            'showRelation',
            'exportRelationExcel',
            'exportRelationPdf',
            'createRelation',
            'updateRelation',
            'deleteRelation',
            'attachRelation',
            'detachRelation',
            'updatePivotRelation',
            'processRelationPagination',
            'process_pagination',
        ];
    }
}
