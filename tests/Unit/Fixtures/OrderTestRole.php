<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class OrderTestRole extends Model
{
    protected $table = 'roles';
    public $timestamps = false;
    protected $guarded = [];

    public const RELATIONS = [];
}
