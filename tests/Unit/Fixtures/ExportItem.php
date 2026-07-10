<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ExportItem extends Model
{
    protected $table = 'export_items';
    protected $fillable = ['id', 'name', 'email'];
}
