<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class OrderTestPost extends Model
{
    protected $table = 'posts';
    public $timestamps = false;
    protected $guarded = [];

    public const RELATIONS = ['user'];

    public function user()
    {
        return $this->belongsTo(OrderTestUser::class, 'user_id');
    }
}
