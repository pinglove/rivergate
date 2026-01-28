<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;

class AsinUserMpSyncUnresolvedLog extends Model
{
    protected $table = 'asins_user_mp_sync_unresolved_logs';

    protected $fillable = [
        'unresolved_id',
        'step',
        'payload',
    ];

    public $timestamps = false;
}
