<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Core\Traits\InteractsWithSoftDelete;

/**
 * Soft-deletable fixture using the conventional `deleted_at` column.
 */
class SoftPost extends BaseModel
{
    use InteractsWithSoftDelete;

    protected $table = 'soft_posts';
    public $timestamps = false;
    protected $guarded = [];

    protected ?string $softDeleteColumn = 'deleted_at';
}
