<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class OrdersSync extends Model
{
    protected $table = 'orders_sync';

    protected $guarded = [];

    public $timestamps = true;
}
