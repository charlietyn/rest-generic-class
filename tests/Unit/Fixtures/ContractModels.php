<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ronu\RestGenericClass\Core\Models\BaseModel;

/**
 * Fixtures for the v3.1.0 contract fixes: a self-referential hierarchy with hidden columns
 * (a secret AND the timestamps, as the generated projects declare them) and a related model
 * with its own hidden column.
 */
class ContractUser extends BaseModel
{
    const MODEL = 'ContractUser';
    const RELATIONS = ['devices', 'father', 'children'];
    const HIERARCHY_FIELD_ID = 'father_id';

    protected $table = 'contract_users';
    protected $guarded = [];
    protected $hidden = ['password', 'created_at', 'updated_at'];
    protected $casts = ['active' => 'boolean'];

    public function devices(): HasMany
    {
        return $this->hasMany(ContractDevice::class, 'user_id');
    }

    public function father(): BelongsTo
    {
        return $this->belongsTo(ContractUser::class, 'father_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ContractUser::class, 'father_id');
    }

    public function hasHierarchyField(): bool
    {
        return true;
    }

    public function getHierarchyFieldId(): ?string
    {
        return 'father_id';
    }
}

class ContractDevice extends BaseModel
{
    const MODEL = 'ContractDevice';
    const RELATIONS = ['user'];

    protected $table = 'contract_devices';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['device_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ContractUser::class, 'user_id');
    }
}
