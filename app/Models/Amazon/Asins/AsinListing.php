<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsinListing extends Model
{
    protected $table = 'asins_asin_listing';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'asin_id',
        'asin',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asin(): BelongsTo
    {
        return $this->belongsTo(Asin::class, 'asin_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AsinListingImage::class, 'listing_id');
    }
}
