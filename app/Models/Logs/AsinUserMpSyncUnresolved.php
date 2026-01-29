<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinUserMpSyncUnresolved extends Model
{
    protected $table = 'asins_user_mp_sync_unresolved';

    protected $guarded = [];

    public $timestamps = true;
}
