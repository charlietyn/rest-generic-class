<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RolePermissionItem extends Model
{
    protected $table = 'role_permission_items';
    public $timestamps = false;
    protected $guarded = [];

    public function scopeNotRestricted(Builder $query): Builder
    {
        return $query->where('restrict', false);
    }
}
