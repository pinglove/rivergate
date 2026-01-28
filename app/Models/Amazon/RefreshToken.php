<?php

namespace App\Models\Amazon;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $table = 'amazon_refresh_tokens';

    protected $fillable = [
        'user_id',
        'amazon_seller_id',
        'marketplace_id',

        // LWA (seller)
        'lwa_refresh_token',

        // LWA (application)
        'lwa_client_id',
        'lwa_client_secret',

        // AWS IAM (application)
        'aws_access_key_id',
        'aws_secret_access_key',
        'aws_role_arn',
        'sp_api_region',

        // meta
        'auth_type',
        'status',
        'last_used_at',
    ];

    protected $casts = [
        // 🔐 чувствительные данные — encrypted cast
        'lwa_refresh_token'     => 'encrypted',
        'lwa_client_secret'     => 'encrypted',
        'aws_secret_access_key' => 'encrypted',

        'last_used_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForMarketplace(Builder $query, int $marketplaceId): Builder
    {
        return $query->where('marketplace_id', $marketplaceId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Отметить токен как использованный
     */
    public function markUsed(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Отозвать токен
     */
    public function revoke(): void
    {
        $this->forceFill([
            'status' => 'revoked',
        ])->save();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /*
    |--------------------------------------------------------------------------
    | Backward compatibility (временная)
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠️ ВАЖНО
     * Если где-то в коде ещё используется $model->refresh_token,
     * этот accessor позволит не сломать старый код.
     * Его можно удалить после полного рефактора.
     */
    public function getRefreshTokenAttribute(): ?string
    {
        return $this->lwa_refresh_token;
    }
}
