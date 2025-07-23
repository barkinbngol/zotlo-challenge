<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionStatusController extends Controller
{
    /**
     * Zotlo webhook endpoint
     */
    public function handleWebhook(Request $request)
    {
        // 1) Logla
        Log::info('Zotlo webhook hit', ['payload' => $request->all()]);

        // 2) Payload’ı normalize et
        $payload = $request->all();

        $profile = data_get($payload, 'profile', $payload);

        $subscriberId = data_get($profile, 'subscriberId');
        $remoteStatus = data_get($profile, 'realStatus', data_get($profile, 'status'));
        $expireDate = data_get($profile, 'expireDate');
        $package = data_get($profile, 'package', data_get($payload, 'packageId'));
        $origTxnId = data_get($profile, 'originalTransactionId'); // DB’de zotlo_subscription_id olarak tutuyoruz
        $cancellation = data_get($profile, 'cancellation');

        if (! $subscriberId || ! $remoteStatus) {
            return response()->json(['error' => 'Eksik parametre'], 400);
        }

        // 3) Local status eşlemesi
        $localStatus = $this->mapStatus($remoteStatus, $cancellation);

        DB::beginTransaction();
        try {
            // a) Kullanıcıyı bul (zotlo_subscriber_id ile)
            $user = User::where('zotlo_subscriber_id', $subscriberId)->first();

            if (! $user) {
                Log::warning('Webhook: user not found for subscriberId', ['subscriberId' => $subscriberId]);
            }

            // b) Abonelik kaydını bul
            $subscription = null;

            if ($origTxnId) {
                $subscription = Subscription::where('zotlo_subscription_id', $origTxnId)->first();
            }

            if (! $subscription && $user) {
                $subscription = Subscription::where('user_id', $user->id)
                    ->latest()
                    ->first();
            }

            // c) Yoksa oluştur (isteğe bağlı)
            if (! $subscription) {
                $subscription = new Subscription;
                $subscription->user_id = $user?->id;
                $subscription->zotlo_subscription_id = $origTxnId;
            }

            // d) Alanları güncelle
            $subscription->status = $localStatus;
            $subscription->package_name = $package ?: $subscription->package_name;
            if ($expireDate) {
                $subscription->expire_date = $expireDate;
            }
            $subscription->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Webhook save error', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }

        return response()->json(['message' => 'Webhook işlendi'], 200);
    }

    /**
     * local status map
     */
    private function mapStatus(string $remoteStatus, $cancellation): string
    {
        $remoteStatus = strtolower($remoteStatus);

        if (in_array($remoteStatus, ['cancelled', 'canceled', 'passive'])) {
            return 'cancelled';
        }

        if ($remoteStatus === 'expired') {
            return 'expired';
        }

        if ($cancellation) {
            return 'cancelled';
        }

        return 'active';
    }
}
