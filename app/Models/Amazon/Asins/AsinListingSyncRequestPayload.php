<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;

class AsinListingSyncRequestPayload extends Model
{
    protected $table = 'asins_asin_listing_sync_request_payloads';

    protected $fillable = [
        'request_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public $timestamps = true;
}
