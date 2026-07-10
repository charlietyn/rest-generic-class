<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class PaginationItem extends Model
{
    protected $table = 'pagination_items';
    public $timestamps = false;
    protected $guarded = [];
}
