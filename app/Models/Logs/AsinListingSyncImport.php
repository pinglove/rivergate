<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinListingSyncImport extends Model
{
    protected $table = 'asins_asin_listing_sync_imports';

    protected $guarded = [];

    public $timestamps = true;
}
