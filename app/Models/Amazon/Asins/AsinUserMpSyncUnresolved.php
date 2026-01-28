<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;

class AsinUserMpSyncUnresolved extends Model
{
    protected $table = 'asins_user_mp_sync_unresolved';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'seller_sku',
        'product_id_type',
        'product_id',
        'title',
        'status',
        'attempts',
        'run_after',
        'last_error',
        'asin_id',
        'raw_row',
    ];

    protected $casts = [
        'run_after' => 'datetime',
    ];

    public $timestamps = true;
}
