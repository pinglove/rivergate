<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinListingSync extends Model
{
    protected $table = 'asins_asin_listing_sync';

    protected $guarded = [];

    public $timestamps = true;
}
