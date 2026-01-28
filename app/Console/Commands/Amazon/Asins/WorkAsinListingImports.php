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
        {--limit=10}
        {--debug}';

    protected $description = 'ASIN listing import worker (W2)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== ASIN LISTING IMPORT WORKER (W2) ===');
            $this->line('limit=' . $limit);
            $this->line('now=' . $now->toDateTimeString());
        }

        for ($i = 0; $i < $limit; $i++) {
            DB::beginTransaction();

            try {
                /** @var AsinListingSyncImport|null $import */
                $import = AsinListingSyncImport::query()
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$import) {
                    DB::commit();
                    if ($debug) {
                        $this->line('No import tasks found');
                    }
                    return Command::SUCCESS;
                }

                $sync = DB::table('asins_asin_listing_sync')->where('id', $import->sync_id)->first();
                if (!$sync) {
                    throw new \RuntimeException("sync not found (sync_id={$import->sync_id})");
                }

                $request = AsinListingSyncRequest::query()
                    ->where('sync_id', $sync->id)
                    ->where('status', 'completed')
                    ->first();

                if (!$request) {
                    throw new \RuntimeException("completed request not found (sync_id={$sync->id})");
                }

                $payloadRow = AsinListingSyncRequestPayload::query()
                    ->where('request_id', $request->id)
                    ->first();

                if (!$payloadRow) {
                    throw new \RuntimeException("payload not found (request_id={$request->id})");
                }

                $payload = $payloadRow->payload;

                if (!is_array($payload) || empty($payload['data']['response'])) {
                    throw new \RuntimeException("invalid payload structure (request_id={$request->id})");
                }

                // mark import processing
                $import->update([
                    'status' => 'processing',
                    'updated_at' => now(),
                ]);

                DB::commit();

                // ============================
                // IMPORT DATA
                // ============================

                DB::beginTransaction();

                // 1️⃣ сохранить RAW snapshot
                DB::table('asins_asin_listing')->updateOrInsert(
                    [
                        'user_id'        => $sync->user_id,
                        'marketplace_id'=> $sync->marketplace_id,
                        'asin_id'        => $sync->asin_id,
                    ],
                    [
                        'data'       => json_encode($payload['data']['response'], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                // 2️⃣ извлечь маленькую MAIN картинку
                $imageUrl = $this->extractSmallMainImage(
                    $payload['data']['response']['images'] ?? []
                );

                if ($imageUrl) {
                    DB::table('asins_asins')
                        ->where('id', $sync->asin_id)
                        ->update([
                            'image_url' => $imageUrl,
                            'updated_at' => now(),
                        ]);
                }

                // 3️⃣ финализация import
                $import->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

                DB::table('asins_asin_listing_sync')
                    ->where('id', $sync->id)
                    ->where('pipeline', 'import')
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'completed',
                        'pipeline' => 'completed',
                        'updated_at' => now(),
                    ]);

                DB::commit();

                if ($debug) {
                    $this->line("✔ import completed (sync_id={$sync->id})");
                }

            } catch (\Throwable $e) {
                DB::rollBack();

                if (isset($import)) {
                    $import->update(['status' => 'error']);
                }

                DB::table('asins_asin_listing_sync')
                    ->where('id', $sync->id ?? null)
                    ->update([
                        'status' => 'error',
                        'updated_at' => now(),
                    ]);

                $this->error(
                    'ERROR import sync_id=' . ($sync->id ?? 'n/a') . ': ' . $e->getMessage()
                );
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Выбрать самую маленькую MAIN-картинку
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
