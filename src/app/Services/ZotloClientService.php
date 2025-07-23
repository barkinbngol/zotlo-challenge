<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ZotloRequestFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZotloClientService
{
    protected string $accessKey;

    protected string $accessSecret;

    protected int $appId;

    protected string $baseUrl;

    public function __construct()
    {
        $this->accessKey = (string) config('services.zotlo.access_key');
        $this->accessSecret = (string) config('services.zotlo.access_secret');
        $this->appId = (int) config('services.zotlo.app_id');
        $this->baseUrl = app()->environment('production')
            ? 'https://api.zotlo.com'
            : 'https://test-api.zotlo.com';
    }

    public function startSubscriptionWithCard(array $data): array
    {
        $masked = $this->maskCardPayload($data);
        Log::info('Zotlo subscription request', $masked);

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/v1/payment/credit-card", [
                    'cardNo' => $data['cardNo'],
                    'cardOwner' => $data['cardOwner'],
                    'expireMonth' => $data['expireMonth'],
                    'expireYear' => $data['expireYear'],
                    'cvv' => $data['cvv'],
                    'packageId' => $data['packageId'],
                    'subscriberId' => $data['subscriberId'],
                    'subscriberPhoneNumber' => $data['subscriberPhoneNumber'],
                    'subscriberCountry' => $data['subscriberCountry'],
                    'subscriberIpAddress' => $data['subscriberIpAddress'],
                    'redirectUrl' => $data['redirectUrl'],
                    'language' => $data['language'] ?? 'tr',
                    'platform' => $data['platform'] ?? 'web',
                ]);

            $this->logResponse('Zotlo subscription response', $response);

            if ($response->successful()) {
                $body = $response->json();
                $profile = data_get($body, 'result.profile');
                $package = data_get($body, 'result.package');

                return [
                    'success' => true,
                    'zotlo_subscription_id' => data_get($profile, 'originalTransactionId')
                                              ?? data_get($body, 'result.subscriptionId'),
                    'expire_date' => data_get($profile, 'expireDate'),
                    'package_id' => data_get($package, 'packageId') ?? $data['packageId'],
                    'raw' => $body,
                ];
            }

            throw new ZotloRequestFailed(
                payload: $masked,
                response: $response->json() ?? [],
                httpStatus: $response->status(),
                message: data_get($response->json(), 'meta.errorMessage', 'Zotlo API hatası')
            );
        } catch (\Throwable $e) {
            Log::error('Zotlo exception', ['message' => $e->getMessage()]);
            throw $e instanceof ZotloRequestFailed
                ? $e
                : new ZotloRequestFailed($masked, [], 500, $e->getMessage());
        }
    }

    public function getSubscriptions(string $subscriberId): array
    {
        $response = $this->http(false)
            ->get("{$this->baseUrl}/v1/subscription/list", [
                'subscriberId' => $subscriberId,
                'appId' => $this->appId,
            ]);

        $this->logResponse('Zotlo list response', $response);

        if ($response->failed()) {
            throw new ZotloRequestFailed(
                ['subscriberId' => $subscriberId],
                $response->json() ?? [],
                $response->status(),
                'Abonelik listesi alınamadı.'
            );
        }

        return ['success' => true, 'raw' => $response->json()];
    }

    public function cancelSubscription(
        string $subscriberId,
        string $packageId,
        string $reason = 'user_request',
        bool $force = false
    ): array {
        $payload = [
            'subscriberId' => $subscriberId,
            'packageId' => $packageId,
            'cancellationReason' => $reason,
            'force' => $force ? 1 : 0,
        ];

        $response = $this->http()
            ->post("{$this->baseUrl}/v1/subscription/cancellation", $payload);

        $this->logResponse('Zotlo cancel response', $response);

        if ($response->successful()) {
            return ['success' => true, 'raw' => $response->json()];
        }

        throw new ZotloRequestFailed(
            $payload,
            $response->json() ?? [],
            $response->status(),
            data_get($response->json(), 'meta.errorMessage', 'İptal başarısız.')
        );
    }

    public function getSavedCards(string $subscriberId): array
    {
        $response = $this->http()
            ->withHeaders(['ApplicationId' => $this->appId])
            ->get("{$this->baseUrl}/v1/subscription/card-list", [
                'subscriberId' => $subscriberId,
            ]);

        $this->logResponse('Zotlo card-list response', $response);

        if ($response->successful()) {
            return ['success' => true, 'raw' => $response->json()];
        }

        throw new ZotloRequestFailed(
            ['subscriberId' => $subscriberId],
            $response->json() ?? [],
            $response->status(),
            data_get($response->json(), 'meta.errorMessage', 'Kart listesi alınamadı.')
        );
    }

    protected function defaultHeaders(bool $withAppId = true): array
    {
        $headers = [
            'AccessKey' => $this->accessKey,
            'AccessSecret' => $this->accessSecret,
            'Language' => 'tr',
        ];

        if ($withAppId) {
            $headers['ApplicationId'] = $this->appId;
        }

        return $headers;
    }

    protected function http(bool $withAppId = true): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders($this->defaultHeaders($withAppId))
            ->timeout(10)
            ->retry(3, 250); // 3 deneme, 250ms bekleme
    }

    protected function maskCardPayload(array $data): array
    {
        $masked = $data;
        if (isset($masked['cardNo'])) {
            $masked['cardNo'] = $this->maskNumber($masked['cardNo']);
        }
        if (isset($masked['cvv'])) {
            $masked['cvv'] = '***';
        }

        return $masked;
    }

    protected function maskNumber(string $number): string
    {
        return preg_replace('/\d(?=\d{4})/', '*', $number);
    }

    protected function logResponse(string $msg, Response $response): void
    {
        $json = $response->json();
        Log::info($msg, [
            'status' => $response->status(),
            'meta' => data_get($json, 'meta', []),
        ]);
    }
}
