<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\User;
use App\Models\Marketplace;

class AsinUserMpSync extends Model
{
    protected $table = 'asins_user_mp_sync';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'status',
        'attempts',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'user_id'        => 'int',
        'marketplace_id'=> 'int',
        'attempts'       => 'int',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class, 'marketplace_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AsinUserMpSyncLog::class, 'sync_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Status helpers (минимум, без логики)
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isAnalyzing(): bool
    {
        return $this->status === 'analyzing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }
}
