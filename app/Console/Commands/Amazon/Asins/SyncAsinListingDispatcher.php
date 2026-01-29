<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Asins\AsinListingSync;
use App\Models\Amazon\Asins\AsinListingSyncRequest;
use App\Models\Amazon\Asins\AsinListingSyncImport;

class SyncAsinListingDispatcher extends Command
{
    protected $signature = 'asins:dispatch-listing-sync
        {--limit=250 : Max sync jobs per run}
        {--debug : Debug output}';

    protected $description = 'Dispatcher for ASIN listing sync (request → import)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== ASIN LISTING SYNC DISPATCHER ===');
            $this->line('limit=' . $limit);
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
        |--------------------------------------------------------------------------
        | PHASE 1: DISPATCH REQUEST
        |--------------------------------------------------------------------------
        */

        DB::beginTransaction();

        try {
            $syncs = AsinListingSync::query()
                ->where('status', 'pending')
                ->where('pipeline', 'pending')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($syncs->isEmpty()) {
                DB::commit();

                if ($debug) {
                    $this->line('No request-phase syncs found');
                }
                // ❗ НЕ return — продолжаем в PHASE 2
            } else {
                foreach ($syncs as $sync) {
                    if ($debug) {
                        $this->line(sprintf(
                            'try sync_id=%d asin_id=%d status=%s pipeline=%s',
                            $sync->id,
                            $sync->asin_id,
                            $sync->status,
                            $sync->pipeline
                        ));
                    }

                    $requestExists = AsinListingSyncRequest::query()
                        ->where('sync_id', $sync->id)
                        ->exists();

                    if (!$requestExists) {
                        AsinListingSyncRequest::query()->create([
                            'sync_id'    => $sync->id,
                            'status'     => 'pending',
                            'attempts'   => 0,
                            'run_after'  => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    AsinListingSync::query()
                        ->where('id', $sync->id)
                        ->where('status', 'pending')
                        ->where('pipeline', 'pending')
                        ->update([
                            'status'     => 'processing',
                            'pipeline'   => 'request',
                            'updated_at' => $now,
                        ]);

                    if ($debug) {
                        $this->line('  → request phase dispatched');
                    }
                }

                DB::commit();
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Dispatcher failed (request phase): ' . $e->getMessage());
            return Command::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | PHASE 2: DISPATCH IMPORT
        |--------------------------------------------------------------------------
        */

        if ($debug) {
            $this->line('--- ENTERING IMPORT PHASE ---');
        }

        DB::beginTransaction();

        try {
            $syncsForImport = AsinListingSync::query()
                ->where('status', 'pending')
                ->where('pipeline', 'import')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($debug) {
                $this->line('Found import syncs: ' . $syncsForImport->count());
            }

            if ($syncsForImport->isEmpty()) {
                DB::commit();
                return Command::SUCCESS;
            }

            foreach ($syncsForImport as $sync) {
                if ($debug) {
                    $this->line(sprintf(
                        'import sync_id=%d asin_id=%d',
                        $sync->id,
                        $sync->asin_id
                    ));
                }

                $importExists = AsinListingSyncImport::query()
                    ->where('sync_id', $sync->id)
                    ->exists();

                if (!$importExists) {
                    AsinListingSyncImport::query()->create([
                        'sync_id'    => $sync->id,
                        'status'     => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                AsinListingSync::query()
                    ->where('id', $sync->id)
                    ->where('status', 'pending')
                    ->where('pipeline', 'import')
                    ->update([
                        'status'     => 'processing',
                        'updated_at' => $now,
                    ]);

                if ($debug) {
                    $this->line('  → import phase dispatched');
                }
            }

            DB::commit();
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Dispatcher failed (import phase): ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
