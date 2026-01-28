<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;

class AsinListingSyncPayload extends Model
{
    protected $table = 'asins_asin_listing_sync_payloads';

    protected $fillable = [
        'source',   // amazon
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}
