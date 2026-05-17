<?php
/**
 * NakoPay Payment Driver for Invoice Ninja v5
 *
 * Handles the hosted checkout redirect flow and webhook processing.
 *
 * @package App\PaymentDrivers\NakoPay
 * @license MIT
 */

namespace App\PaymentDrivers;

use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\SystemLog;
use App\PaymentDrivers\NakoPay\NakoPayApiClient;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;

class NakoPayPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $refundable = true;
    public $token_billing = false;
    public $can_authorise_credit_card = false;

    private NakoPayApiClient $apiClient;

    public function init(): self
    {
        $this->apiClient = new NakoPayApiClient(
            apiKey: $this->company_gateway->getConfigField('apiKey'),
            testMode: (bool) $this->company_gateway->getConfigField('testMode'),
        );

        return $this;
    }

    /**
     * Returns the gateway types supported
     */
    public function gatewayTypes(): array
    {
        return [
            GatewayType::CRYPTO,
        ];
    }

    /**
     * View for the payment authorization
     */
    public function processPaymentView(array $data)
    {
        $this->init();

        $paymentHash = PaymentHash::where('hash', $data['payment_hash'])->firstOrFail();
        $invoices = Invoice::whereIn('id', $this->transformKeys(
            array_column($paymentHash->invoices(), 'invoice_id')
        ))->get();

        $description = $invoices->map(fn ($inv) => $inv->number)->implode(', ');

        $result = $this->apiClient->createPaymentLink([
            'amount' => $data['total']['amount_with_fee'],
            'currency' => $this->client->currency()->code ?? 'USD',
            'description' => "Invoice Ninja: {$description}",
            'metadata' => [
                'payment_hash' => $data['payment_hash'],
                'company_key' => $this->company_gateway->company->company_key,
            ],
            'redirect_url' => route('client.payments.response', [
                'company_gateway_id' => $this->company_gateway->id,
                'payment_hash' => $data['payment_hash'],
                'payment_method_id' => GatewayType::CRYPTO,
            ]),
        ]);

        if (isset($result['error'])) {
            throw new \Exception($result['error']['message'] ?? 'Failed to create invoice');
        }

        $checkoutUrl = $result['url'] ?? $result['checkout_url'] ?? $result['hosted_url'];

        return redirect()->away($checkoutUrl);
    }

    /**
     * Process the payment response (redirect back)
     */
    public function processPaymentResponse(Request $request)
    {
        $paymentHash = PaymentHash::where('hash', $request->payment_hash)->firstOrFail();

        // At this point, the webhook should have already updated the payment.
        // If not, we show a pending state.
        return redirect()->route('client.invoices.index')
            ->with('message', 'Payment is being processed. You will be notified once confirmed.');
    }

    /**
     * Handle webhook callback
     */
    public function processWebhookRequest(Request $request): \Illuminate\Http\Response
    {
        $this->init();

        $rawBody = $request->getContent();
        $signature = $request->header('X-NakoPay-Signature', '');
        $secret = $this->company_gateway->getConfigField('webhookSecret') ?? '';

        if ($secret && !$this->verifySignature($rawBody, $signature, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        $event = $payload['event'] ?? $payload['type'] ?? '';
        $data = $payload['data'] ?? $payload;
        $metadata = $data['metadata'] ?? [];

        if (!isset($metadata['payment_hash'])) {
            return response()->json(['error' => 'Missing payment_hash'], 400);
        }

        $paymentHash = PaymentHash::where('hash', $metadata['payment_hash'])->first();
        if (!$paymentHash) {
            return response()->json(['error' => 'Unknown payment_hash'], 404);
        }

        if (in_array($event, ['invoice.paid', 'invoice.confirmed'])) {
            $this->createPayment(
                paymentHash: $paymentHash,
                amount: (float) ($data['amount'] ?? 0),
                transactionId: $data['id'] ?? $data['invoice_id'] ?? '',
            );
        } elseif ($event === 'invoice.expired') {
            SystemLog::create([
                'company_id' => $this->company_gateway->company_id,
                'type_id' => SystemLog::TYPE_GATEWAY_RESPONSE,
                'category_id' => SystemLog::CATEGORY_GATEWAY_RESPONSE,
                'event_id' => SystemLog::EVENT_GATEWAY_FAILURE,
                'log' => ['event' => $event, 'data' => $data],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Process a refund
     */
    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        $this->init();

        $result = $this->apiClient->refundInvoice($payment->transaction_reference);

        if (isset($result['error'])) {
            return [
                'transaction_reference' => $payment->transaction_reference,
                'transaction_response' => $result,
                'success' => false,
                'description' => $result['error']['message'] ?? 'Refund failed',
                'code' => 422,
            ];
        }

        return [
            'transaction_reference' => $payment->transaction_reference,
            'transaction_response' => $result,
            'success' => true,
            'description' => 'Refund initiated',
            'code' => 200,
        ];
    }

    /**
     * Create a payment record from a successful webhook
     */
    private function createPayment(PaymentHash $paymentHash, float $amount, string $transactionId): void
    {
        // Check if already processed
        $existing = Payment::where('transaction_reference', $transactionId)->first();
        if ($existing) {
            return;
        }

        $data = [
            'payment_method' => GatewayType::CRYPTO,
            'amount' => $amount,
            'payment_type' => 'NakoPay',
            'transaction_reference' => $transactionId,
            'gateway_type_id' => GatewayType::CRYPTO,
        ];

        $payment = $this->createPaymentRecord($data, $paymentHash);
        $payment->save();
    }

    /**
     * Verify HMAC-SHA256 webhook signature
     */
    private function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
