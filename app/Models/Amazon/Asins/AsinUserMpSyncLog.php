<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsinUserMpSyncLog extends Model
{
    protected $table = 'asins_user_mp_sync_logs';

    public $timestamps = false;

    protected $fillable = [
        'sync_id',
        'pipeline_step',
        'payload',
    ];

    protected $casts = [
        'sync_id'       => 'int',
        'pipeline_step' => 'int',
        'payload'       => 'array',
        'created_at'    => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function sync(): BelongsTo
    {
        return $this->belongsTo(AsinUserMpSync::class, 'sync_id');
    }
}
