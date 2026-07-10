<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelationParentItem extends Model
{
    protected $table = 'relation_parent_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $perPage = 2;

    public function children(): HasMany
    {
        return $this->hasMany(RelationChildItem::class, 'parent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            RelationTagItem::class,
            'relation_parent_tag',
            'parent_item_id',
            'tag_item_id'
        )->withPivot(['label', 'is_primary']);
    }
}
