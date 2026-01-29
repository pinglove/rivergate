<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinListingSyncRequestPayload extends Model
{
    protected $table = 'asins_asin_listing_sync_request_payloads';

    protected $guarded = [];

    public $timestamps = true;
}
