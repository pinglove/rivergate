<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsinListingImage extends Model
{
    protected $table = 'asins_asin_listing_images';

    protected $fillable = [
        'listing_id',
        'variant',
        'url',
        'width',
        'height',
        'sort',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(AsinListing::class, 'listing_id');
    }
}
