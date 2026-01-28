<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Asins\AsinUserMpSyncUnresolved;
use App\Models\Amazon\RefreshToken;

class WorkUnresolvedAsins extends Command
{
    protected $signature = 'asins:work-unresolved
        {--limit=10}
        {--debug}';

    protected $description = 'Resolve unresolved ASINs by EAN / UPC via Catalog API';

    private const MAX_ATTEMPTS = 5;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== UNRESOLVED ASIN RESOLVER ===');
            $this->line('limit=' . $limit);
            $this->line('now=' . $now->toDateTimeString());
        }

        for ($i = 0; $i < $limit; $i++) {
            DB::beginTransaction();

            try {
                /** @var AsinUserMpSyncUnresolved|null $row */
                $row = AsinUserMpSyncUnresolved::query()
                    ->where('status', 'pending')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('run_after')
                          ->orWhere('run_after', '<=', $now);
                    })
                    ->where('attempts', '<', self::MAX_ATTEMPTS)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    DB::commit();
                    if ($debug) {
                        $this->line('No unresolved rows found');
                    }
                    return Command::SUCCESS;
                }

                $marketplace = DB::table('marketplaces')
                    ->where('id', $row->marketplace_id)
                    ->first();

                if (! $marketplace || ! $marketplace->amazon_id) {
                    throw new \RuntimeException(
                        "marketplace.amazon_id missing (marketplace_id={$row->marketplace_id})"
                    );
                }

                $token = RefreshToken::query()
                    ->where('user_id', $row->user_id)
                    ->where('marketplace_id', $row->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (! $token) {
                    throw new \RuntimeException(
                        "RefreshToken not found (user_id={$row->user_id}, marketplace_id={$row->marketplace_id})"
                    );
                }

                $productIdType = $this->mapProductIdType($row->product_id_type);
                if (! $productIdType || ! $row->product_id) {
                    $row->update([
                        'status'      => 'failed',
                        'last_error'  => 'Unsupported or missing product_id_type / product_id',
                        'updated_at'  => now(),
                    ]);

                    DB::commit();
                    continue;
                }

                // mark processing + increment attempts
                $row->update([
                    'status'     => 'processing',
                    'attempts'   => $row->attempts + 1,
                    'updated_at' => now(),
                ]);

                DB::commit();

                // ---------- NODE COMMAND ----------
                $cmd = [
                    'node',
                    'spapi/asins/resolveByIdentifier.js',

                    '--request_id=' . $row->id,
                    '--marketplace_id=' . $marketplace->amazon_id,
                    '--product_id_type=' . $productIdType,
                    '--product_id=' . $row->product_id,

                    '--lwa_refresh_token=' . $token->lwa_refresh_token,
                    '--lwa_client_id=' . $token->lwa_client_id,
                    '--lwa_client_secret=' . $token->lwa_client_secret,
                    '--aws_access_key_id=' . $token->aws_access_key_id,
                    '--aws_secret_access_key=' . $token->aws_secret_access_key,
                    '--aws_role_arn=' . $token->aws_role_arn,
                    '--sp_api_region=' . ($token->sp_api_region ?? 'eu'),
                ];

                if ($debug) {
                    $this->line("NODE CMD:\n  " . implode("\n  ", $cmd));
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
                if (! $json) {
                    throw new \RuntimeException('Invalid JSON from resolver');
                }

                // ---------- RESULT HANDLING ----------
                if (($json['success'] ?? false) === true) {

                    match ($json['status'] ?? null) {
                        'resolved' => $this->handleResolved($row, $json),
                        'ambiguous' => $row->update([
                            'status' => 'ambiguous',
                            'updated_at' => now(),
                        ]),
                        'not_found' => $row->update([
                            'status' => 'not_found',
                            'updated_at' => now(),
                        ]),
                        default => $this->handleFail($row, $json),
                    };

                } else {
                    $this->handleFail($row, $json);
                }

            } catch (\Throwable $e) {
                DB::rollBack();

                if (isset($row)) {
                    $row->update([
                        'status'     => $row->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                        'run_after'  => now()->addMinutes(5),
                        'last_error' => $e->getMessage(),
                        'updated_at'=> now(),
                    ]);
                }

                $this->error(
                    'ERROR unresolved_id=' . ($row->id ?? 'n/a') . ': ' . $e->getMessage()
                );
            }
        }

        return Command::SUCCESS;
    }

    // ---------------- helpers ----------------

    private function mapProductIdType(?string $type): ?string
    {
        return match ((string) $type) {
            '4', 'EAN'  => 'EAN',
            '3', 'UPC'  => 'UPC',
            'GTIN'      => 'GTIN',
            default     => null,
        };
    }

    private function handleResolved(AsinUserMpSyncUnresolved $row, array $json): void
    {
        $asin = $json['resolved_asin'] ?? null;

        if (! $asin) {
            $this->handleFail($row, ['error' => 'resolved without asin']);
            return;
        }

        $asinId = DB::table('asins_asins')
            ->where('user_id', $row->user_id)
            ->where('marketplace_id', $row->marketplace_id)
            ->where('asin', $asin)
            ->value('id');

        if (! $asinId) {
            $asinId = DB::table('asins_asins')->insertGetId([
                'user_id'        => $row->user_id,
                'marketplace_id'=> $row->marketplace_id,
                'asin'           => $asin,
                'title'          => $row->title,
                'status'         => 'active',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $row->update([
            'status'     => 'resolved',
            'asin_id'    => $asinId,
            'run_after'  => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function handleFail(AsinUserMpSyncUnresolved $row, array $json): void
    {
        $retryAfter = (int) ($json['retry_after_minutes'] ?? 5);

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $row->update([
                'status'     => 'failed',
                'run_after'  => null,
                'last_error' => $json['error'] ?? 'Max attempts reached',
                'updated_at' => now(),
            ]);
            return;
        }

        $row->update([
            'status'     => 'pending',
            'run_after'  => now()->addMinutes($retryAfter),
            'last_error' => $json['error'] ?? 'Retry scheduled',
            'updated_at' => now(),
        ]);
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
