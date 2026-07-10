<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TargetRoleItem extends Model
{
    protected $table = 'target_role_items';
    public $timestamps = false;
    protected $guarded = [];
}
