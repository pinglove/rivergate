<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class AsinUserMpSyncUnresolvedLog extends Model
{
    protected $table = 'asins_user_mp_sync_unresolved_logs';

    protected $guarded = [];

    public $timestamps = false; // created_at есть, updated_at нет
}
