<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class ReviewRequestQueue extends Model
{
    protected $table = 'review_request_queue';

    protected $guarded = [];

    public $timestamps = true;
}
