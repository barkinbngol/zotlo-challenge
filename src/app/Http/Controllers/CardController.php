<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CardController extends Controller
{
    public function list(Request $request)
    {
        $user = $request->user();

        if (! $user->zotlo_subscriber_id) {
            return response()->json(['error' => 'Kullanıcının Zotlo subscriberId bilgisi yok.'], 400);
        }

        $service = app(\App\Services\ZotloClientService::class);
        $resp = $service->getSavedCards($user->zotlo_subscriber_id);
        if (! $resp['success']) {
            return response()->json([
                'error' => 'Kartlar alınamadı',
                'details' => $resp['details'] ?? $resp,
            ], 422);
        }

        $cards = data_get($resp, 'raw.result.cardList', []);

        return response()->json([
            'count' => count($cards),
            'cards' => $cards,
            'zotlo_response' => $resp['raw'], // debug için
        ]);
    }
}
