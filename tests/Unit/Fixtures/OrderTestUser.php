<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class OrderTestUser extends Model
{
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];

    public const RELATIONS = ['role', 'posts', 'parent'];

    public function role()
    {
        return $this->belongsTo(OrderTestRole::class, 'role_id');
    }

    public function posts()
    {
        return $this->hasMany(OrderTestPost::class, 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(OrderTestUser::class, 'parent_id');
    }
}
