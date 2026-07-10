<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TargetPermissionItem extends Model
{
    protected $table = 'target_permission_items';
    public $timestamps = false;
    protected $guarded = [];
}
