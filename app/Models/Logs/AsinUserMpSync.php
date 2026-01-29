<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinUserMpSync extends Model
{
    protected $table = 'asins_user_mp_sync';

    protected $guarded = [];

    public $timestamps = true;
}
