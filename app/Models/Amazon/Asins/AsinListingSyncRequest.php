<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsinListingSyncRequest extends Model
{
    protected $table = 'asins_asin_listing_sync_requests';

    protected $fillable = [
        'sync_id',
        'status',      // pending | processing | completed | fail | error
        'attempts',
        'payload_id',
        'last_error',
        'run_after',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'run_after' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sync(): BelongsTo
    {
        return $this->belongsTo(AsinListingSync::class, 'sync_id');
    }

    public function payload(): BelongsTo
    {
        return $this->belongsTo(AsinListingSyncPayload::class, 'payload_id');
    }
    
    public function payloads(): HasMany
    {
        return $this->hasMany(AsinListingSyncRequestPayload::class, 'request_id');
    }

    public function latestPayload(): ?AsinListingSyncRequestPayload
    {
        return $this->payloads()->latest('id')->first();
    }
}
