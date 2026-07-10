<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class HierarchyItem extends Model
{
    protected $table = 'hierarchy_items';
    public $timestamps = false;
    protected $guarded = [];

    public function hasHierarchyField(): bool
    {
        return true;
    }

    public function getHierarchyFieldId(): ?string
    {
        return 'parent_id';
    }
}
