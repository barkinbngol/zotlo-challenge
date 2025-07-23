<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\ZotloClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected ZotloClientService $zotlo;

    public function __construct(ZotloClientService $zotlo)
    {
        $this->zotlo = $zotlo;
    }

    /*
      Abonelik başlatma
     */
    public function subscribe(Request $request): JsonResponse
    {
        Log::info('Subscribe fonksiyonu çalıştı');

        $user = auth()->user();

        // Lokalde abonelik kontrolü
        if (Subscription::where('user_id', $user->id)->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Zaten aktif bir aboneliğiniz var.'], 400);
        }

        $subscriberId = $user->getOrCreateZotloSubscriberId();

        // Zotloda abonelik kontrolü
        $remote = $this->zotlo->getSubscriptions($subscriberId);
        if ($remote['success']) {
            $hasActive = collect(data_get($remote, 'raw.result', []))
                ->firstWhere('realStatus', 'active');
            if ($hasActive) {
                return response()->json([
                    'error' => 'Zotlo tarafında zaten aktif aboneliğiniz var.',
                    'details' => $remote['raw'],
                ], 409);
            }
        }

        // Kart & zorunlu alanlar validasyonu
        $validated = $request->validate([
            'cardNo' => 'required|string',
            'cardOwner' => 'required|string',
            'expireMonth' => 'required|string|size:2',
            'expireYear' => 'required|string|size:2',
            'cvv' => 'required|string|min:3|max:4',
            'packageId' => 'required|string',
            'subscriberPhoneNumber' => 'required|string',
            'subscriberCountry' => 'required|string|size:2',
            'subscriberIpAddress' => 'required|ip',
            'redirectUrl' => 'required|url',
            'language' => 'nullable|string|size:2',
            'platform' => 'nullable|string',
        ]);

        // Servise giderken subscriberId üretilen
        $validated['subscriberId'] = $subscriberId;

        $result = $this->zotlo->startSubscriptionWithCard($validated);

        if (! $result['success']) {
            return response()->json([
                'error' => $result['error'],
                'details' => $result['details'],
            ], 422);
        }

        // DB Kaydı
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'zotlo_subscription_id' => $result['zotlo_subscription_id'],
            'status' => 'active',
            'package_name' => $result['package_id'] ?? $validated['packageId'],
            'expire_date' => $result['expire_date'],
        ]);

        return response()->json([
            'message' => 'Abonelik başarıyla başlatıldı.',
            'subscription_id' => $subscription->subscription_id,
            'zotlo_subscription_id' => $subscription->zotlo_subscription_id,
            'zotlo_response' => $result['raw'],
        ]);
    }

    /**
     * Abonelik durumu sorgulama
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json([
                'status' => 'not_subscribed',
                'message' => 'Kullanıcının aktif bir aboneliği yok.',
            ]);
        }

        return response()->json([
            'status' => $subscription->status,
            'package' => $subscription->package_name ?? 'unknown',
            'expire_date' => $subscription->expire_date,
        ]);
    }

    /**
     * Abonelik iptali
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = auth()->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['error' => 'Aktif bir abonelik bulunamadı.'], 404);
        }

        $reason = $request->input('reason', 'user_request');
        $force = (bool) $request->input('force', false);

        // subscriberId User tablosundan
        if (! $user->zotlo_subscriber_id) {
            return response()->json(['error' => 'SubscriberId bulunamadı.'], 422);
        }

        $result = $this->zotlo->cancelSubscription(
            $user->zotlo_subscriber_id,
            $subscription->package_name,
            $reason,
            $force
        );

        if (! $result['success']) {
            return response()->json([
                'error' => $result['error'],
                'details' => $result['details'],
            ], 422);
        }

        $subscription->status = 'cancelled';
        $subscription->save();

        return response()->json([
            'message' => 'Abonelik başarıyla iptal edildi.',
            'zotlo_response' => $result['raw'],
        ]);
    }
}
