<?php

namespace App\Models\Amazon\Review;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ReviewRequestSetting extends Model
{
    protected $table = 'review_request_settings';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'asin_id',
        'is_enabled',
        'delay_days',
        'process_hour',
        'settings_meta',
    ];

    protected $casts = [
        'is_enabled'    => 'boolean',
        'delay_days'    => 'integer',
        'process_hour' => 'integer',
        'settings_meta'=> 'array',
    ];

    /* -----------------------------------------------------------------
     | Scopes
     | -----------------------------------------------------------------
     */

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForMarketplace(Builder $query, int $marketplaceId): Builder
    {
        return $query->where('marketplace_id', $marketplaceId);
    }

    public function scopeForAsin(Builder $query, int $asinId): Builder
    {
        return $query->where('asin_id', $asinId);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /* -----------------------------------------------------------------
     | Helpers
     | -----------------------------------------------------------------
     */

    public static function getOrCreateForAsin(
        int $userId,
        int $marketplaceId,
        int $asinId
    ): self {
        return static::firstOrCreate(
            [
                'user_id'        => $userId,
                'marketplace_id'=> $marketplaceId,
                'asin_id'        => $asinId,
            ],
            [
                'is_enabled'    => false,
                'delay_days'    => 5,
                'process_hour'  => 2,
            ]
        );
    }
}
