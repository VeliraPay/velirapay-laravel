<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Testing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use VeliraPay\Enums\EventType;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * Builds webhook payloads shaped like the ones VeliraPay sends, for tests.
 */
final class FakeWebhook
{
    /**
     * Build a delivery's payload.
     *
     * @param  array<string, mixed>  $object  Attributes to set on the charge or invoice.
     * @param  array<string, mixed>  $payload  Attributes to set on the payload itself, such as "mode" or "test".
     * @return array<string, mixed>
     */
    public static function payload(EventType|string $type, array $object = [], array $payload = []): array
    {
        $type = $type instanceof EventType ? $type->value : $type;
        $now = Carbon::now('UTC');

        if (str_starts_with($type, 'invoice.')) {
            $data = ['invoice' => array_replace(self::invoice($type, $now), $object)];
        } else {
            $data = ['charge' => $charge = array_replace(self::charge($type, $now), $object)];
            $transactions = is_array($charge['transactions'] ?? null) ? $charge['transactions'] : [];

            if (in_array($type, ['charge.payment_detected', 'charge.late_payment'], true) && $transactions !== []) {
                $data['transaction'] = $transactions[array_key_last($transactions)];
            }
        }

        return array_replace([
            'id' => (string) Str::uuid(),
            'event_id' => (string) Str::uuid(),
            'event' => $type,
            'mode' => 'test',
            'created_at' => $now->toISOString(),
            'data' => $data,
        ], $payload);
    }

    /**
     * Build a delivery, as the webhook route would hand it to listeners.
     *
     * @param  array<string, mixed>  $object  Attributes to set on the charge or invoice.
     * @param  array<string, mixed>  $payload  Attributes to set on the payload itself, such as "mode" or "test".
     */
    public static function event(EventType|string $type, array $object = [], array $payload = []): WebhookEvent
    {
        return WebhookEvent::fromArray(self::payload($type, $object, $payload));
    }

    /**
     * Build a charge in the state the event leaves it in.
     *
     * @return array<string, mixed>
     */
    private static function charge(string $type, Carbon $now): array
    {
        $status = match ($type) {
            'charge.paid', 'charge.refunded' => 'paid',
            'charge.underpaid' => 'underpaid',
            'charge.expired', 'charge.late_payment' => 'expired',
            'charge.canceled' => 'canceled',
            default => 'pending',
        };

        $received = match ($status) {
            'paid' => '0.000400000000000000',
            'underpaid' => '0.000200000000000000',
            default => null,
        };

        $transfer = match ($type) {
            'charge.payment_detected' => self::transaction('0.000400000000000000', 0, false, $now),
            'charge.late_payment' => self::transaction('0.000400000000000000', 2, false, $now),
            default => $received === null ? null : self::transaction($received, 2, true, $now),
        };

        return [
            'code' => Str::lower(Str::random(12)),
            'status' => $status,
            'fiat_amount' => '25.00',
            'fiat_currency' => 'USD',
            'asset' => 'BTC',
            'asset_amount' => '0.000400000000000000',
            'received_amount' => $received,
            'remaining_amount' => match ($status) {
                'paid' => '0',
                'underpaid' => '0.000200000000000000',
                default => '0.000400000000000000',
            },
            'overpaid' => false,
            'refunded_amount' => $type === 'charge.refunded' ? '0.000400000000000000' : '0',
            'exchange_rate' => '62500.000000000000000000',
            'deposit_address' => 'tb1qtest0000000000000000000000000000000000',
            'transactions' => $transfer === null ? [] : [$transfer],
            'customer_email' => 'customer@example.com',
            'customer_name' => null,
            'customer' => [
                'email' => 'customer@example.com',
                'name' => null,
                'ip_address' => '203.0.113.7',
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
                'reference' => null,
                'phone' => null,
                'country' => null,
                'metadata' => [],
            ],
            'custom_fields' => [],
            'description' => null,
            'metadata' => [],
            'payment_link' => null,
            'invoice' => null,
            'created_at' => $now->copy()->subMinutes(5)->toISOString(),
            'expires_at' => $now->copy()->addMinutes(25)->toISOString(),
            'paid_at' => $status === 'paid' ? $now->toISOString() : null,
        ];
    }

    /**
     * Build a transfer into the charge's deposit address.
     *
     * @return array<string, mixed>
     */
    private static function transaction(string $amount, int $confirmations, bool $credited, Carbon $now): array
    {
        $txid = bin2hex(random_bytes(32));

        return [
            'txid' => $txid,
            'amount' => $amount,
            'confirmations' => $confirmations,
            'required_confirmations' => 2,
            'credited' => $credited,
            'explorer_url' => "https://mempool.space/testnet/tx/{$txid}",
            'seen_at' => $now->copy()->subMinutes(2)->toISOString(),
        ];
    }

    /**
     * Build an invoice in the state the event leaves it in.
     *
     * @return array<string, mixed>
     */
    private static function invoice(string $type, Carbon $now): array
    {
        $code = Str::lower(Str::random(12));
        $status = match ($type) {
            'invoice.paid' => 'paid',
            'invoice.voided' => 'void',
            default => 'open',
        };

        return [
            'code' => $code,
            'number' => 'INV-0001',
            'status' => $status,
            'amount' => '100.00',
            'currency' => 'USD',
            'items' => [
                ['description' => 'Services', 'quantity' => '1', 'unit_amount' => '100.00', 'total' => '100.00'],
            ],
            'customer_name' => 'Customer',
            'customer_email' => 'customer@example.com',
            'memo' => null,
            'due_at' => $now->copy()->addDays(14)->toDateString(),
            'hosted_url' => "https://velirapay.com/i/{$code}",
            'charges' => [],
            'sent_at' => in_array($type, ['invoice.sent', 'invoice.viewed', 'invoice.paid'], true) ? $now->copy()->subHour()->toISOString() : null,
            'viewed_at' => in_array($type, ['invoice.viewed', 'invoice.paid'], true) ? $now->copy()->subMinutes(10)->toISOString() : null,
            'paid_at' => $status === 'paid' ? $now->toISOString() : null,
            'voided_at' => $status === 'void' ? $now->toISOString() : null,
            'created_at' => $now->copy()->subDay()->toISOString(),
        ];
    }
}
