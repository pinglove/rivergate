<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinListingSyncRequest extends Model
{
    protected $table = 'asins_asin_listing_sync_requests';

    protected $guarded = [];

    public $timestamps = true;
}
