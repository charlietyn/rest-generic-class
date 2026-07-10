<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class RelationTagItem extends Model
{
    protected $table = 'relation_tag_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $fillable = ['name'];
}
