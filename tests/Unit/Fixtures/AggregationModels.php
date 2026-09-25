<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ronu\RestGenericClass\Core\Contracts\HasRestAggregates;

abstract class AggregateEntity extends Model implements HasRestAggregates
{
    public $timestamps = false;
    protected $guarded = [];
    public const RELATIONS = [];

    public function getRestAggregateColumns(): array
    {
        return ['*' => ['count'], 'id' => ['count', 'min', 'max'], 'amount' => ['count', 'sum', 'avg', 'min', 'max']];
    }
}

class AggregateCustomer extends AggregateEntity
{
    use SoftDeletes;
    protected $table = 'agg_customers';
    public const RELATIONS = ['orders', 'firstOrder', 'lines', 'firstLine', 'tags', 'reports', 'notes'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $q) => $q->where($q->getModel()->qualifyColumn('tenant_id'), 1));
    }

    public function orders() { return $this->hasMany(AggregateOrder::class, 'customer_code', 'code'); }
    public function firstOrder() { return $this->hasOne(AggregateOrder::class, 'customer_code', 'code'); }
    public function lines() { return $this->hasManyThrough(AggregateLine::class, AggregateOrder::class, 'customer_code', 'order_id', 'code', 'id'); }
    public function firstLine() { return $this->hasOneThrough(AggregateLine::class, AggregateOrder::class, 'customer_code', 'order_id', 'code', 'id'); }
    public function tags() { return $this->belongsToMany(AggregateTag::class, 'agg_customer_tag', 'customer_id', 'tag_id')->wherePivot('active', 1); }
    public function reports() { return $this->hasMany(self::class, 'manager_id'); }
    public function notes() { return $this->morphMany(AggregateNote::class, 'notable'); }
    public function getDisplayNameAttribute() { return $this->name; }
    public static function isSoftDeletable(): bool { return true; }
}

class AggregateOrder extends AggregateEntity
{
    use SoftDeletes;
    protected $table = 'agg_orders';
    public const RELATIONS = ['customer', 'lines'];
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $q) => $q->where($q->getModel()->qualifyColumn('tenant_id'), 1));
    }
    public function customer() { return $this->belongsTo(AggregateCustomer::class, 'customer_code', 'code'); }
    public function lines() { return $this->hasMany(AggregateLine::class, 'order_id'); }
}

class AggregateLine extends AggregateEntity { protected $table = 'agg_lines'; }
class AggregateTag extends AggregateEntity { protected $table = 'agg_tags'; }
class AggregateNote extends AggregateEntity
{
    protected $table = 'agg_notes';
    public const RELATIONS = ['notable'];
    public function notable() { return $this->morphTo(); }
}
class AggregateDenied extends Model
{
    protected $table = 'agg_orders';
    public const RELATIONS = [];
}

class AggregateVisibleCustomer extends AggregateCustomer
{
    protected $visible = ['id'];
}

class AggregateImplicitCustomer extends Model
{
    protected $table = 'agg_customers';

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AggregateOrder::class, 'customer_code', 'code');
    }
}

class AggregateDeclaredCustomer extends AggregateImplicitCustomer implements \Ronu\RestGenericClass\Core\Contracts\HasRestRelations
{
    public function getRestRelations(): array
    {
        return ['orders'];
    }
}
