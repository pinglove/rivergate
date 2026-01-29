<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Asins\AsinListingSyncImport;
use App\Models\Amazon\Asins\AsinListingSyncRequest;
use App\Models\Amazon\Asins\AsinListingSyncRequestPayload;

class WorkAsinListingImports extends Command
{
    protected $signature = 'asins:work-listing-imports
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'ASIN listing import worker (W2, supervisor-ready)';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $debug     = (bool) $this->option('debug');
        $idleSleep = max(1, (int) $this->option('sleep'));

        // debug-only controls
        $limit = $debug ? max(0, (int) $this->option('limit')) : 0;
        $once  = $debug ? (bool) $this->option('once') : false;

        $processed = 0;

        // graceful exit
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        if ($debug) {
            $this->info('=== ASIN LISTING IMPORT WORKER (W2) ===');
            $this->line('mode=DEBUG');
            $this->line('limit=' . ($limit ?: '∞'));
            $this->line('once=' . ($once ? 'YES' : 'NO'));
            $this->line('sleep=' . $idleSleep);
        }

        while (! $this->shouldStop) {

            // debug soft-limit
            if ($limit > 0 && $processed >= $limit) {
                if ($debug) {
                    $this->warn("debug limit {$limit} reached → exit");
                }
                break;
            }

            $worked = $this->processOne($debug);

            if ($worked) {
                $processed++;

                if ($once) {
                    if ($debug) {
                        $this->warn('debug --once → exit');
                    }
                    break;
                }

                continue;
            }

            // idle
            if ($debug) {
                $this->line('idle…');
            }

            sleep($idleSleep);
        }

        if ($debug) {
            $this->warn('Worker exited gracefully');
        }

        return Command::SUCCESS;
    }

    private function processOne(bool $debug): bool
    {
        DB::beginTransaction();

        try {
            /** @var AsinListingSyncImport|null $import */
            $import = AsinListingSyncImport::query()
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $import) {
                DB::commit();
                return false;
            }

            $sync = DB::table('asins_asin_listing_sync')
                ->where('id', $import->sync_id)
                ->lockForUpdate()
                ->first();

            if (! $sync) {
                throw new \RuntimeException("sync not found (sync_id={$import->sync_id})");
            }

            $request = AsinListingSyncRequest::query()
                ->where('sync_id', $sync->id)
                ->where('status', 'completed')
                ->first();

            if (! $request) {
                throw new \RuntimeException("completed request not found (sync_id={$sync->id})");
            }

            $payloadRow = AsinListingSyncRequestPayload::query()
                ->where('request_id', $request->id)
                ->first();

            if (! $payloadRow || !is_array($payloadRow->payload)) {
                throw new \RuntimeException("payload not found or invalid (request_id={$request->id})");
            }

            $payload = $payloadRow->payload;

            if (empty($payload['data']['response'])) {
                throw new \RuntimeException("invalid payload structure (request_id={$request->id})");
            }

            // mark import processing
            $import->update([
                'status'     => 'processing',
                'updated_at' => now(),
            ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            if (isset($import)) {
                $import->update(['status' => 'error']);
            }

            if (isset($sync)) {
                DB::table('asins_asin_listing_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status'     => 'error',
                        'updated_at' => now(),
                    ]);
            }

            $this->error('DB ERROR: ' . $e->getMessage());
            return true;
        }

        // ============================
        // IMPORT DATA (no locks here)
        // ============================

        try {
            DB::beginTransaction();

            // 1️⃣ RAW snapshot
            DB::table('asins_asin_listing')->updateOrInsert(
                [
                    'user_id'         => $sync->user_id,
                    'marketplace_id' => $sync->marketplace_id,
                    'asin_id'        => $sync->asin_id,
                ],
                [
                    'data'       => json_encode($payload['data']['response'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // 2️⃣ smallest MAIN image
            $imageUrl = $this->extractSmallMainImage(
                $payload['data']['response']['images'] ?? []
            );

            if ($imageUrl) {
                DB::table('asins_asins')
                    ->where('id', $sync->asin_id)
                    ->update([
                        'image_url'  => $imageUrl,
                        'updated_at' => now(),
                    ]);
            }

            // 3️⃣ finalize import
            $import->update([
                'status'     => 'completed',
                'updated_at' => now(),
            ]);

            DB::table('asins_asin_listing_sync')
                ->where('id', $sync->id)
                ->where('pipeline', 'import')
                ->where('status', 'processing')
                ->update([
                    'status'     => 'completed',
                    'pipeline'   => 'completed',
                    'updated_at' => now(),
                ]);

            DB::commit();

            if ($debug) {
                $this->line("✔ import completed (sync_id={$sync->id})");
            }

        } catch (\Throwable $e) {
            DB::rollBack();

            $import->update(['status' => 'error']);

            DB::table('asins_asin_listing_sync')
                ->where('id', $sync->id)
                ->update([
                    'status'     => 'error',
                    'updated_at' => now(),
                ]);

            $this->error(
                'IMPORT ERROR sync_id=' . $sync->id . ': ' . $e->getMessage()
            );
        }

        return true;
    }

    /**
     * Pick smallest MAIN image
     */
    private function extractSmallMainImage(array $images): ?string
    {
        $all = [];

        foreach ($images as $block) {
            if (!isset($block['images']) || !is_array($block['images'])) {
                continue;
            }

            foreach ($block['images'] as $img) {
                if (($img['variant'] ?? null) !== 'MAIN') {
                    continue;
                }

                $w = $img['width']  ?? 99999;
                $h = $img['height'] ?? 99999;

                $all[] = [
                    'link' => $img['link'] ?? null,
                    'area' => $w * $h,
                ];
            }
        }

        if (empty($all)) {
            return null;
        }

        usort($all, fn ($a, $b) => $a['area'] <=> $b['area']);

        return $all[0]['link'] ?? null;
    }
}
