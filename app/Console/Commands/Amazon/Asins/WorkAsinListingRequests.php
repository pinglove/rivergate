<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Asins\AsinListingSyncRequest;
use App\Models\Amazon\Asins\AsinListingSyncRequestPayload;
use App\Models\Amazon\RefreshToken;

class WorkAsinListingRequests extends Command
{
    protected $signature = 'asins:work-listing-requests
        {--limit=10}
        {--debug}';

    protected $description = 'ASIN listing request worker (W1)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== ASIN LISTING REQUEST WORKER (W1) ===');
            $this->line('limit=' . $limit);
            $this->line('now=' . $now->toDateTimeString());
        }

        for ($i = 0; $i < $limit; $i++) {
            DB::beginTransaction();

            try {
                $request = AsinListingSyncRequest::query()
                    ->whereIn('status', ['pending', 'fail'])
                    ->where('run_after', '<=', $now)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$request) {
                    DB::commit();
                    if ($debug) {
                        $this->line('No request tasks found');
                    }
                    return Command::SUCCESS;
                }

                $sync = DB::table('asins_asin_listing_sync')
                    ->where('id', $request->sync_id)
                    ->lockForUpdate()
                    ->first();

                if (!$sync) {
                    throw new \RuntimeException("sync not found (sync_id={$request->sync_id})");
                }

                $asin = DB::table('asins_asins')->where('id', $sync->asin_id)->first();
                if (!$asin) {
                    throw new \RuntimeException("asin not found (asin_id={$sync->asin_id})");
                }

                $marketplace = DB::table('marketplaces')->where('id', $sync->marketplace_id)->first();
                if (!$marketplace || !$marketplace->amazon_id) {
                    throw new \RuntimeException("marketplace.amazon_id missing (marketplace_id={$sync->marketplace_id})");
                }

                $token = RefreshToken::query()
                    ->where('user_id', $sync->user_id)
                    ->where('marketplace_id', $sync->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (!$token) {
                    throw new \RuntimeException("RefreshToken not found (user_id={$sync->user_id}, marketplace_id={$sync->marketplace_id})");
                }

                // mark request processing
                $request->update([
                    'status'     => 'processing',
                    'attempts'   => $request->attempts + 1,
                    'updated_at' => now(),
                ]);

                DB::commit();

                // ---------- NODE COMMAND ----------
                $cmd = [
                    'node',
                    'spapi/asins/requestCatalogItem.js',
                    '--request_id=' . $request->id,
                    '--marketplace_id=' . $marketplace->amazon_id,
                    '--asin=' . $asin->asin,

                    '--lwa_refresh_token=' . $token->lwa_refresh_token,
                    '--lwa_client_id=' . $token->lwa_client_id,
                    '--lwa_client_secret=' . $token->lwa_client_secret,
                    '--aws_access_key_id=' . $token->aws_access_key_id,
                    '--aws_secret_access_key=' . $token->aws_secret_access_key,
                    '--aws_role_arn=' . $token->aws_role_arn,
                    '--sp_api_region=' . $token->sp_api_region,
                ];

                if ($debug) {
                    $masked = array_map(
                        fn ($v) => str_contains($v, '=') ? preg_replace('/=.*/', '=***', $v) : $v,
                        $cmd
                    );
                    $this->line('NODE CMD: ' . implode(' ', $masked));
                }

                $process = proc_open(
                    implode(' ', array_map('escapeshellarg', $cmd)),
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path()
                );

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                proc_close($process);

                if ($debug) {
                    $this->line("NODE STDOUT:\n" . $stdout);
                    $this->line("NODE STDERR:\n" . $stderr);
                }

                $json = $this->extractLastJson($stdout);
                if (!$json) {
                    throw new \RuntimeException('Invalid JSON from node');
                }

                AsinListingSyncRequestPayload::updateOrCreate(
                    ['request_id' => $request->id],
                    ['payload' => $json]
                );

                // ---------- SUCCESS PATH ----------
                if (($json['success'] ?? false) === true) {
                    DB::transaction(function () use ($request, $sync) {
                        $request->update([
                            'status'     => 'completed',
                            'updated_at' => now(),
                        ]);

                        DB::table('asins_asin_listing_sync')
                            ->where('id', $sync->id)
                            ->where('status', 'processing')
                            ->where('pipeline', 'request')
                            ->update([
                                'status'     => 'pending',
                                'pipeline'   => 'import',
                                'updated_at' => now(),
                            ]);
                    });
                } else {
                    $request->update([
                        'status' => ($json['status'] ?? 'fail') === 'fail' ? 'fail' : 'error',
                        'run_after' => isset($json['retry_after_minutes'])
                            ? now()->addMinutes((int) $json['retry_after_minutes'])
                            : null,
                    ]);
                }

            } catch (\Throwable $e) {
                DB::rollBack();

                if (isset($request)) {
                    $request->update(['status' => 'error']);
                }

                $this->error(
                    'ERROR request_id=' . ($request->id ?? 'n/a') . ': ' . $e->getMessage()
                );
            }
        }

        return Command::SUCCESS;
    }

    private function extractLastJson(string $stdout): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($stdout));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if ($line[0] === '{') {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        return null;
    }
}
