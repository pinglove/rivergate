<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMarketplace extends Model
{
    protected $table = 'user_marketplaces';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'is_enabled',
    ];

    protected $casts = [
        'user_id'        => 'int',
        'marketplace_id'=> 'int',
        'is_enabled'     => 'bool',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForMarketplace(Builder $query, int $marketplaceId): Builder
    {
        return $query->where('marketplace_id', $marketplaceId);
    }
}
