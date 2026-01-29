<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class OrdersItemsSync extends Model
{
    protected $table = 'orders_items_sync';

    protected $guarded = [];

    public $timestamps = true;
}
