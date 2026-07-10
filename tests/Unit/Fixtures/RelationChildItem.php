<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class RelationChildItem extends Model
{
    protected $table = 'relation_child_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $fillable = ['parent_id', 'name', 'status'];
}
