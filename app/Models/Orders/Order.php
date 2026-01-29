<?php

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = true;

    /**
     * 🔎 Scope: активный marketplace
     */
    public function scopeForActiveMarketplace(Builder $query): Builder
    {
        return $query->when(
            session('active_marketplace'),
            fn (Builder $q, $mp) => $q->where('marketplace_id', $mp)
        );
    }
}
