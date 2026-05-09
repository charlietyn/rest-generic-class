<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class OrderTestClient extends Model
{
    protected $table = 'clients';
    public $timestamps = false;
    protected $guarded = [];

    // 'role' is intentionally omitted from RELATIONS to verify whitelist enforcement.
    public const RELATIONS = ['user'];

    public function user()
    {
        return $this->belongsTo(OrderTestUser::class, 'user_id');
    }

    public function role()
    {
        // Method exists but NOT in RELATIONS — used to test 400 rejection path.
        return $this->hasOne(OrderTestRole::class);
    }
}
