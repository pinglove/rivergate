<?php

namespace App\Models\Amazon\Orders;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'datetime',
        'last_updated_date' => 'datetime',

        'earliest_ship_date' => 'datetime',
        'latest_ship_date' => 'datetime',
        'earliest_delivery_date' => 'datetime',
        'latest_delivery_date' => 'datetime',

        'is_business_order' => 'boolean',
        'is_prime' => 'boolean',
        'is_premium_order' => 'boolean',
        'is_replacement_order' => 'boolean',
        'is_sold_by_ab' => 'boolean',
        'is_ispu' => 'boolean',
        'is_global_express_enabled' => 'boolean',
        'is_access_point_order' => 'boolean',
        'has_regulated_items' => 'boolean',
        'is_iba' => 'boolean',
    ];
}
