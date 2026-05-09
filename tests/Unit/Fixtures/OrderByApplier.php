<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Ronu\RestGenericClass\Core\Traits\HasDynamicOrderBy;

class OrderByApplier
{
    use HasDynamicOrderBy;

    public function apply($query, $params, $modelContext)
    {
        return $this->applyDynamicOrderBy($query, $params, $modelContext);
    }
}
