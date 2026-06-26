<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Ronu\RestGenericClass\Core\Models\BaseModel;

/**
 * Non-soft-deletable fixture: no $softDeleteColumn, no trait. Must keep the
 * historic physical-delete behaviour (backward compatibility).
 */
class HardItem extends BaseModel
{
    protected $table = 'hard_items';
    public $timestamps = false;
    protected $guarded = [];
}
