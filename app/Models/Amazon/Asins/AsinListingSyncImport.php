<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsinListingSyncImport extends Model
{
    protected $table = 'asins_asin_listing_sync_imports';

    protected $fillable = [
        'sync_id',
        'request_id',
        'status',     // pending | processing | completed | error
        'last_error',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sync(): BelongsTo
    {
        return $this->belongsTo(AsinListingSync::class, 'sync_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AsinListingSyncRequest::class, 'request_id');
    }
}
