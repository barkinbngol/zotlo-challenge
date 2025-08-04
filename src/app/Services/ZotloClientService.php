<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZotloClientService
{
    protected string $accessKey;
    protected string $accessSecret;
    protected int    $appId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->accessKey    = (string) config('services.zotlo.access_key');
        $this->accessSecret = (string) config('services.zotlo.access_secret');
        $this->appId        = (int)    config('services.zotlo.app_id');
        $this->baseUrl      = app()->environment('production')
            ? 'https://api.zotlo.com'
            : 'https://test-api.zotlo.com';
    }

    public function startSubscriptionWithCard(array $data): array
    {
        Log::info('Zotlo subscription request', $data);

        try {
            $response = Http::withHeaders($this->defaultHeaders())
                ->post("{$this->baseUrl}/v1/payment/credit-card", [
                    'cardNo'                => $data['cardNo'],
                    'cardOwner'             => $data['cardOwner'],
                    'expireMonth'           => $data['expireMonth'],
                    'expireYear'            => $data['expireYear'],
                    'cvv'                   => $data['cvv'],
                    'packageId'             => $data['packageId'],
                    'subscriberId'          => $data['subscriberId'],
                    'subscriberPhoneNumber' => $data['subscriberPhoneNumber'],
                    'subscriberCountry'     => $data['subscriberCountry'],
                    'subscriberIpAddress'   => $data['subscriberIpAddress'],
                    'redirectUrl'           => $data['redirectUrl'],
                    'language'              => $data['language'] ?? 'tr',
                    'platform'              => $data['platform'] ?? 'web',
                ]);

            Log::info('Zotlo response', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            if ($response->successful()) {
                $body     = $response->json();
                $profile  = data_get($body, 'result.profile');
                $package  = data_get($body, 'result.package');

                return [
                    'success'               => true,
                    'zotlo_subscription_id' => data_get($profile, 'originalTransactionId')
                                              ?? data_get($body, 'result.subscriptionId'),
                    'expire_date'           => data_get($profile, 'expireDate'),
                    'package_id'            => data_get($package, 'packageId') ?? $data['packageId'],
                    'raw'                   => $body,
                ];
            }

            return $this->errorArray($response, 'Zotlo API hatası');
        } catch (\Throwable $e) {
            Log::error('Zotlo exception', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'error'   => 'İstek sırasında bir hata oluştu',
                'details' => $e->getMessage(),
            ];
        }
    }
    public function getSubscriptions(string $subscriberId): array
    {
        $response = Http::withHeaders([
            'AccessKey'    => $this->accessKey,
            'AccessSecret' => $this->accessSecret,
        ])->get("{$this->baseUrl}/v1/subscription/list", [
            'subscriberId' => $subscriberId,
            'appId'        => $this->appId,
        ]);

        if ($response->failed()) {
            return ['success' => false, 'details' => $response->json()];
        }

        return ['success' => true, 'raw' => $response->json()];
    }


    public function cancelSubscription(
        string $subscriberId,
        string $packageId,
        string $reason = 'user_request',
        bool   $force  = false
    ): array {
        $payload = [
            'subscriberId'       => $subscriberId,
            'packageId'          => $packageId,
            'cancellationReason' => $reason,
            'force'              => $force ? 1 : 0,
        ];

        $response = Http::withHeaders($this->defaultHeaders())
            ->post("{$this->baseUrl}/v1/subscription/cancellation", $payload);

        if ($response->successful()) {
            return [
                'success' => true,
                'raw'     => $response->json(),
            ];
        }

        return $this->errorArray($response, 'İptal başarısız.');
    }


    public function getSavedCards(string $subscriberId): array
    {
        $response = Http::withHeaders([
            'AccessKey'     => $this->accessKey,
            'AccessSecret'  => $this->accessSecret,
            'ApplicationId' => $this->appId,
            'Language'      => 'tr',
        ])->get("{$this->baseUrl}/v1/subscription/card-list", [
            'subscriberId' => $subscriberId,
        ]);

        if ($response->successful()) {
            return ['success' => true, 'raw' => $response->json()];
        }

        return [
            'success' => false,
            'error'   => data_get($response->json(), 'meta.errorMessage', 'Kart listesi alınamadı.'),
            'details' => $response->json(),
        ];
    }




    protected function defaultHeaders(bool $withAppId = true): array
    {
        $headers = [
            'AccessKey'    => $this->accessKey,
            'AccessSecret' => $this->accessSecret,
            'Language'     => 'tr',
        ];

        if ($withAppId) {
            $headers['ApplicationId'] = $this->appId;
        }

        return $headers;
    }


    protected function errorArray(Response $response, string $fallback = 'Hata'): array
    {
        $json = $response->json();
        return [
            'success' => false,
            'error'   => data_get($json, 'meta.errorMessage', $fallback),
            'details' => $json,
        ];
    }
}
