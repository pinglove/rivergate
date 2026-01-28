<?php

namespace App\Models\Amazon\Asins;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Models\Amazon\Asins\AsinService;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Review\ReviewRequestSetting;

class Asin extends Model
{
    protected $table = 'asins_asins';

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'asin',
        'title',
        'image_url',
        'status',
    ];

    protected $casts = [
        'user_id'        => 'int',
        'marketplace_id'=> 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    
    public function listing(): HasOne
    {
        return $this->hasOne(AsinListing::class, 'asin_id');
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(AsinListingSync::class, 'asin_id');
    }
    

    public function scopeEligibleForListingSync($query, int $userId, int $marketplaceId)
    {
        $cutoff = now()->subHours(config('amazon.sync_listing_max_hours'));

        return $query
            ->where('user_id', $userId)
            ->where('marketplace_id', $marketplaceId)

            /**
             * 1️⃣ НЕТ активных sync (pending / processing)
             */
            ->whereDoesntHave('syncJobs', function ($q) {
                $q->whereIn('status', ['pending', 'processing']);
            })

            /**
             * 2️⃣ ЛИБО sync вообще не было
             * 3️⃣ ЛИБО последняя sync слишком старая
             */
            ->where(function ($q) use ($cutoff) {

                // никогда не синкался
                $q->whereDoesntHave('syncJobs')

                // или последняя sync < cutoff
                ->orWhereHas('syncJobs', function ($sq) use ($cutoff) {
                    $sq->whereRaw('asins_asin_listing_sync.id = (
                        SELECT MAX(id)
                        FROM asins_asin_listing_sync
                        WHERE asin_id = asins_asins.id
                    )')
                    ->where('created_at', '<', $cutoff);
                });
            });
    }
    
    public function reviewRequestSetting()
    {
        return $this->hasOne(
            ReviewRequestSetting::class,
            'asin_id',
            'id'
        )
        ->where('user_id', auth()->id())
        ->where('marketplace_id', (int) session('active_marketplace'));
    }
}
