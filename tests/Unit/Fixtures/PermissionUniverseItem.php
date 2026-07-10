<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class PermissionUniverseItem extends Model
{
    protected $table = 'permission_universe_items';
    public $timestamps = false;
    protected $guarded = [];
}
