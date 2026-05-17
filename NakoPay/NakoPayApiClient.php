<?php
/**
 * NakoPay API Client for Invoice Ninja
 *
 * @package App\PaymentDrivers\NakoPay
 */

namespace App\PaymentDrivers\NakoPay;

use Illuminate\Support\Facades\Http;

class NakoPayApiClient
{
    private const API_BASE = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1';
    private const API_VERSION = '2025-04-20';

    private string $apiKey;
    private bool $testMode;

    public function __construct(string $apiKey, bool $testMode = false)
    {
        $this->apiKey = $apiKey;
        $this->testMode = $testMode;
    }

    public function createPaymentLink(array $data): array
    {
        return $this->request('POST', '/payment-links', $data);
    }

    public function getInvoice(string $id): array
    {
        return $this->request('GET', "/invoices/{$id}");
    }

    public function refundInvoice(string $id): array
    {
        return $this->request('POST', "/invoices/{$id}/refund");
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'User-Agent' => 'nakopay-invoiceninja/1.0.0',
            'X-NakoPay-Version' => self::API_VERSION,
        ];

        if ($method === 'POST') {
            $headers['Idempotency-Key'] = 'idem_' . bin2hex(random_bytes(16));
        }

        $url = self::API_BASE . $path;

        $response = match ($method) {
            'POST' => Http::withHeaders($headers)->timeout(30)->post($url, $data ?? []),
            'GET' => Http::withHeaders($headers)->timeout(30)->get($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            return [
                'error' => [
                    'message' => $response->json('message') ?? "HTTP {$response->status()}",
                    'status' => $response->status(),
                ],
            ];
        }

        return $response->json() ?? [];
    }
}
