<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AsinListingSync extends Model
{
    protected $table = 'asins_asin_listing_sync';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'asin_id',
        'status',    // pending | processing | completed | error
        'pipeline',  // pending | request | import | completed
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asin(): BelongsTo
    {
        return $this->belongsTo(Asin::class, 'asin_id');
    }

    public function request(): HasOne
    {
        return $this->hasOne(AsinListingSyncRequest::class, 'sync_id');
    }

    public function import(): HasOne
    {
        return $this->hasOne(AsinListingSyncImport::class, 'sync_id');
    }
    
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Marketplace::class, 'marketplace_id');
    }
}
