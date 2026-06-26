<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Core\Traits\InteractsWithSoftDelete;

/**
 * Soft-deletable fixture using a CUSTOM column (`archived_at`) instead of
 * the conventional `deleted_at`. Proves the mechanism is column-driven.
 */
class SoftArchive extends BaseModel
{
    use InteractsWithSoftDelete;

    protected $table = 'soft_archives';
    public $timestamps = false;
    protected $guarded = [];

    protected ?string $softDeleteColumn = 'archived_at';
}
