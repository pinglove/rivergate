<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\UserMarketplace;
use App\Models\Amazon\RefreshToken;

class Marketplace extends Model
{
    protected $table = 'marketplaces';

    protected $fillable = [
        'code',
        'country',
        'currency',
        'locale',
        'is_active',
    ];

    protected $casts = [
        'id'        => 'int',
        'code'      => 'string',
        'country'   => 'string',
        'currency'  => 'string',
        'locale'    => 'string',
        'is_active' => 'bool',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function userMarketplaces(): HasMany
    {
        return $this->hasMany(UserMarketplace::class, 'marketplace_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'marketplace_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper($code));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function label(): string
    {
        return $this->code;
    }
}
