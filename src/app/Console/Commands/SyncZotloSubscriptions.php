<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ZotloClientService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncZotloSubscriptions extends Command
{
    protected $signature = 'sync-zotlo-subscriptions {--chunk=500} {--dry-run}';

    protected $description = 'Zotlo ile abonelik durumlarını periyodik olarak senkronize eder.';

    public function handle(ZotloClientService $zotlo): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Sync started (chunk={$chunk}, dry-run=".($dryRun ? 'yes' : 'no').')');
        Log::info('Sync job started', ['chunk' => $chunk, 'dry_run' => $dryRun]);

        $processed = 0;
        $changed = 0;

        Subscription::query()
            ->whereIn('status', ['active', 'pending', 'trial'])
            ->orderBy('id')
            ->chunkById($chunk, function ($subs) use ($zotlo, $dryRun, &$processed, &$changed) {
                foreach ($subs as $sub) {
                    $processed++;

                    $user = $sub->user;
                    if (! $user || ! $user->zotlo_subscriber_id) {
                        continue;
                    }

                    try {
                        $resp = $zotlo->getSubscriptions($user->zotlo_subscriber_id);
                    } catch (\Throwable $e) {
                        Log::warning('Sync exception while calling Zotlo', [
                            'user_id' => $user->id,
                            'sub_id' => $sub->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    if (! $resp['success']) {
                        Log::warning('Sync fail from Zotlo', [
                            'user_id' => $user->id,
                            'sub_id' => $sub->id,
                            'details' => $resp['details'] ?? null,
                        ]);

                        continue;
                    }

                    $remoteList = collect(data_get($resp, 'raw.result', []));
                    $remoteItem = $remoteList->firstWhere('originalTransactionId', $sub->zotlo_subscription_id);

                    if (! $remoteItem) {
                        $remoteItem = $remoteList
                            ->where('package', $sub->package_name)
                            ->sortByDesc('startDate')
                            ->first();
                    }

                    if (! $remoteItem) {
                        continue;
                    }

                    $oldStatus = $sub->status;
                    $newStatus = $this->mapStatus($remoteItem['realStatus'] ?? ($remoteItem['status'] ?? ''));

                    $expireDate = $remoteItem['expireDate'] ?? null;
                    $expireDate = $expireDate ? Carbon::parse($expireDate) : $sub->expire_date;

                    $dirty = false;

                    if ($oldStatus !== $newStatus) {
                        $sub->status = $newStatus;
                        $dirty = true;
                    }

                    $remotePackage = $remoteItem['package'] ?? $sub->package_name;
                    if ($sub->package_name !== $remotePackage) {
                        $sub->package_name = $remotePackage;
                        $dirty = true;
                    }

                    if ($expireDate && (! $sub->expire_date || ! $sub->expire_date->equalTo($expireDate))) {
                        $sub->expire_date = $expireDate;
                        $dirty = true;
                    }

                    if ($dirty) {
                        if (! $dryRun) {
                            $sub->save();
                        }
                        $changed++;

                        Log::info('Subscription updated from Zotlo', [
                            'subscription_id' => $sub->id,
                            'user_id' => $user->id,
                            'old_status' => $oldStatus,
                            'new_status' => $newStatus,
                            'expire_date' => $expireDate,
                            'package' => $sub->package_name,
                            'dry_run' => $dryRun,
                        ]);
                    }
                }
            });

        $this->info("Sync finished. processed={$processed}, changed={$changed}");
        Log::info('Sync job finished', ['processed' => $processed, 'changed' => $changed]);

        return self::SUCCESS;
    }

    private function mapStatus(string $remote): string
    {
        $remote = strtolower($remote);

        return match ($remote) {
            'active' => 'active',
            'passive',
            'cancelled',
            'canceled' => 'cancelled',
            'expired' => 'expired',
            'trial' => 'trial',
            'pending' => 'pending',
            default => 'active',
        };
    }
}
