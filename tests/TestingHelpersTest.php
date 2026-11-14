<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use VeliraPay\Enums\Asset;
use VeliraPay\Enums\ChargeStatus;
use VeliraPay\Enums\EventType;
use VeliraPay\Enums\Mode;
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Events\InvoiceVoided;
use VeliraPay\Laravel\Events\WebhookReceived;
use VeliraPay\Laravel\Http\Controllers\WebhookController;
use VeliraPay\Laravel\Testing\FakeWebhook;
use VeliraPay\Laravel\Testing\SendsVeliraPayWebhooks;
use VeliraPay\Webhooks\Webhook;
use VeliraPay\Webhooks\WebhookEvent;

final class TestingHelpersTest extends TestCase
{
    use SendsVeliraPayWebhooks;

    public function test_a_signed_webhook_can_be_sent_to_the_application(): void
    {
        Event::fake();

        $this->postVeliraPayWebhook(EventType::ChargePaid, ['metadata' => ['order_id' => '42']])->assertOk();

        Event::assertDispatched(ChargePaid::class, fn (ChargePaid $event): bool => $event->charge->isPaid()
            && $event->charge->metadata === ['order_id' => '42']
            && $event->charge->receivedAmount === $event->charge->assetAmount);
    }

    public function test_a_webhook_is_signed_even_without_a_configured_secret(): void
    {
        Event::fake();
        config(['velirapay.webhook.secret' => null]);

        $this->postVeliraPayWebhook('invoice.voided')->assertOk();

        Event::assertDispatched(InvoiceVoided::class, fn (InvoiceVoided $event): bool => $event->invoice->isVoid());
    }

    #[DefineEnvironment('turnRouteOff')]
    public function test_a_webhook_can_be_sent_to_a_route_of_your_own(): void
    {
        Event::fake();
        Route::post('payments/velirapay', WebhookController::class);

        $this->postVeliraPayWebhook('charge.paid', uri: '/payments/velirapay')->assertOk();

        Event::assertDispatched(ChargePaid::class);
    }

    #[DefineEnvironment('turnRouteOff')]
    public function test_a_webhook_needs_a_uri_while_the_route_is_turned_off(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('pass the URI of your own route to postVeliraPayWebhook()');

        $this->postVeliraPayWebhook('charge.paid');
    }

    public function test_a_fake_payment_detected_delivery_carries_an_unconfirmed_transfer(): void
    {
        $webhook = FakeWebhook::event('charge.payment_detected');

        $this->assertTrue($webhook->charge?->isPending());
        $this->assertNotNull($webhook->transaction);
        $this->assertSame(0, $webhook->transaction->confirmations);
        $this->assertFalse($webhook->transaction->credited);
        $this->assertSame($webhook->charge->transactions[0]->txid, $webhook->transaction->txid);
        $this->assertSame('203.0.113.7', $webhook->charge->customer->ipAddress);
        $this->assertNull(FakeWebhook::event('charge.paid')->transaction);
    }

    public function test_fake_payout_deliveries_carry_the_charge_and_its_payout(): void
    {
        $sent = FakeWebhook::event('payout.sent');
        $confirmed = FakeWebhook::event('payout.confirmed');

        $this->assertTrue($sent->charge?->isPaid());
        $this->assertSame($sent->charge->id, $sent->payout?->charge);
        $this->assertSame('sending', $sent->payout->status);
        $this->assertNull($sent->payout->confirmedAt);
        $this->assertTrue($confirmed->payout?->isSent());
        $this->assertNotNull($confirmed->payout->confirmedAt);
        $this->assertNull(FakeWebhook::event('charge.paid')->payout);
    }

    public function test_a_fake_payout_takes_attributes_of_its_own(): void
    {
        $webhook = FakeWebhook::event('payout.confirmed', [
            'metadata' => ['order_id' => '42'],
            'payout' => ['network_fee' => '0.000007', 'sent_amount' => '0.000386'],
        ]);

        $this->assertSame('0.000007', $webhook->payout?->networkFee);
        $this->assertSame('0.000386', $webhook->payout->sentAmount);
        $this->assertSame('0.000393', $webhook->payout->netAmount);
        $this->assertSame(['order_id' => '42'], $webhook->charge?->metadata);
        $this->assertArrayNotHasKey('payout', $webhook->charge->toArray());
    }

    public function test_a_fake_payout_has_the_fields_of_a_real_one_and_the_fee_of_its_charge(): void
    {
        $webhook = FakeWebhook::event('payout.sent');
        $payout = $webhook->payout;
        $terms = $webhook->charge?->processingTerms;

        $this->assertNotNull($payout);
        $this->assertSame([
            'id', 'object', 'charge', 'status', 'asset', 'gross_amount', 'fee_percent', 'fee_amount', 'fee_fiat_amount',
            'fiat_currency', 'exchange_rate', 'net_amount', 'network_fee', 'merchant_network_reserve', 'sent_amount',
            'payout_address', 'payout_txid', 'explorer_url', 'attempts', 'last_error', 'next_attempt_at', 'sent_at',
            'confirmed_at', 'created_at',
        ], array_keys($payout->toArray()));
        $this->assertNull($payout->merchantNetworkReserve);
        $this->assertNotNull($terms);
        $this->assertSame($terms->percent, $payout->feePercent);
        $this->assertSame(['0.0004', '0.000007', '0.000393'], [$payout->grossAmount, $payout->feeAmount, $payout->netAmount]);
        $this->assertSame('0.44', $payout->feeFiatAmount);
        $this->assertSame(['0.0000044', '0.0003886'], [$payout->networkFee, $payout->sentAmount]);
    }

    public function test_a_fake_verification_challenge_is_answered(): void
    {
        Event::fake();

        $this->postVeliraPayWebhook('webhook.verification', ['challenge' => 'c4Lr9xT2mQ7vZ1'])
            ->assertOk()
            ->assertExactJson(['challenge' => 'c4Lr9xT2mQ7vZ1']);

        $challenge = $this->postVeliraPayWebhook(Webhook::VERIFICATION_EVENT)->assertOk()->json('challenge');

        $this->assertIsString($challenge);
        $this->assertSame(40, strlen($challenge));
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_fake_verification_challenge_has_the_shape_of_a_real_one(): void
    {
        $payload = FakeWebhook::payload('webhook.verification');
        $live = FakeWebhook::event('webhook.verification', payload: ['mode' => 'live']);

        $this->assertSame(['id', 'event', 'mode', 'created_at', 'data'], array_keys($payload));
        $this->assertSame('test', $payload['mode']);
        $this->assertTrue($live->isVerification());
        $this->assertTrue($live->isLiveMode());
        $this->assertNull($live->charge);
        $this->assertSame(40, strlen((string) $live->challenge()));
    }

    public function test_fake_deliveries_match_their_type(): void
    {
        $charge = FakeWebhook::event('charge.underpaid')->charge;
        $live = FakeWebhook::event('invoice.paid', ['number' => 'INV-0042'], ['mode' => 'live']);
        $invoice = $live->invoice;

        $this->assertNotNull($charge);
        $this->assertTrue($charge->isUnderpaid());
        $this->assertSame('0.000200000000000000', $charge->remainingAmount);
        $this->assertTrue($live->isLiveMode());
        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->isPaid());
        $this->assertSame('INV-0042', $invoice->number);
        $this->assertNotNull($invoice->viewedAt);
        $this->assertCount(1, $invoice->charges);
        $this->assertSame('paid', $invoice->charges[0]['status']);
    }

    public function test_fake_charges_have_no_coupon_like_real_ones_without_one(): void
    {
        $charge = FakeWebhook::event('charge.paid')->charge;
        $sample = FakeWebhook::event('charge.paid', payload: ['test' => true])->charge;

        $this->assertNotNull($charge);
        $this->assertNotNull($sample);

        foreach ([$charge, $sample] as $fake) {
            $this->assertArrayHasKey('coupon', $fake->toArray());
            $this->assertNull($fake->get('coupon'));
            $this->assertArrayHasKey('discount_amount', $fake->toArray());
            $this->assertNull($fake->get('discount_amount'));
        }
    }

    public function test_fake_charges_have_the_fields_of_real_ones_in_their_order(): void
    {
        $charge = FakeWebhook::event('charge.paid')->charge;

        $this->assertNotNull($charge);
        $this->assertSame([
            'code', 'status', 'fiat_amount', 'fiat_currency', 'coupon', 'discount_amount', 'asset', 'asset_amount',
            'network_surcharge', 'processing_terms', 'switched_from', 'received_amount', 'remaining_amount', 'overpaid',
            'refunded_amount', 'exchange_rate', 'deposit_address', 'payment_request', 'transactions', 'customer_email',
            'customer_name', 'customer', 'custom_fields', 'description', 'metadata', 'payment_link', 'invoice',
            'created_at', 'expires_at', 'paid_at',
        ], array_keys($charge->toArray()));
        $this->assertNull($charge->get('network_surcharge'));
        $this->assertSame('1.75', $charge->processingTerms?->percent);
        $this->assertSame(10, $charge->processingTerms->maxForwardingPercent);
        $this->assertNull($charge->switchedFrom);
        $this->assertNull($charge->get('payment_request'));
    }

    public function test_a_fake_charge_can_be_quoted_again_in_the_coin_that_arrived(): void
    {
        $switchedFrom = [
            'asset' => 'TRX',
            'asset_amount' => '83.333334000000000000',
            'exchange_rate' => '0.300000000000000000',
            'switched_at' => '2026-11-14T12:05:49.000000Z',
        ];

        $webhook = FakeWebhook::event(EventType::ChargeLatePayment, [
            'asset' => Asset::USDT_TRON,
            'asset_amount' => '27.500000000000000000',
            'exchange_rate' => '1.000000000000000000',
            'network_surcharge' => '2.500000000000000000',
            'switched_from' => [...$switchedFrom, 'asset' => Asset::TRX],
        ]);

        $this->assertSame('USDT_TRON', $webhook->charge?->asset);
        $this->assertSame('27.500000000000000000', $webhook->charge->assetAmount);
        $this->assertSame('2.500000000000000000', $webhook->charge->networkSurcharge);
        $this->assertSame($switchedFrom, $webhook->charge->switchedFrom?->toArray());
        $this->assertSame('TRX', $webhook->charge->switchedFrom->asset);
        $this->assertSame('83.333334000000000000', $webhook->charge->switchedFrom->assetAmount);
        $this->assertSame('0.300000000000000000', $webhook->charge->switchedFrom->exchangeRate);
        $this->assertEquals(new DateTimeImmutable('2026-11-14T12:05:49Z'), $webhook->charge->switchedFrom->switchedAt);
        $this->assertSame('25.00', $webhook->charge->fiatAmount);
        $this->assertNotNull($webhook->transaction);
    }

    public function test_fake_customers_have_the_fields_of_real_ones_in_their_order(): void
    {
        $customer = FakeWebhook::event('charge.paid')->charge?->customer;
        $hidden = FakeWebhook::event('charge.paid', ['customer' => ['ip_address' => '198.51.100.4', 'ip_country' => 'T1', 'ip_asn' => 24940, 'ip_network' => 'Hetzner Online GmbH']])->charge?->customer;

        $this->assertNotNull($customer);
        $this->assertSame([
            'email', 'name', 'ip_address', 'ip_country', 'ip_asn', 'ip_network', 'user_agent', 'reference', 'phone', 'country', 'metadata',
        ], array_keys($customer->toArray()));
        $this->assertNull($customer->ipCountry);
        $this->assertNull($customer->ipAsn);
        $this->assertNull($customer->ipNetwork);
        $this->assertSame('T1', $hidden?->ipCountry);
        $this->assertSame(24940, $hidden->ipAsn);
        $this->assertSame('Hetzner Online GmbH', $hidden->ipNetwork);
    }

    public function test_a_fake_test_delivery_is_the_dashboard_sample(): void
    {
        $payload = FakeWebhook::payload(EventType::InvoicePaid, payload: ['test' => true]);
        $webhook = WebhookEvent::fromArray($payload);

        $this->assertArrayNotHasKey('event_id', $payload);
        $this->assertTrue($webhook->test);
        $this->assertSame(EventType::ChargePaid->value, $webhook->type);
        $this->assertNotNull($webhook->charge);
        $this->assertTrue($webhook->charge->isPaid());
        $this->assertSame(['test' => true], $webhook->charge->metadata);
        $this->assertArrayNotHasKey('customer', $webhook->charge->toArray());
        $this->assertArrayNotHasKey('processing_terms', $webhook->charge->toArray());
        $this->assertArrayNotHasKey('switched_from', $webhook->charge->toArray());
    }

    public function test_fake_deliveries_take_enum_cases_for_their_values(): void
    {
        $payload = FakeWebhook::payload(EventType::ChargeUnderpaid, ['asset' => Asset::LTC, 'status' => ChargeStatus::Underpaid], ['mode' => Mode::Live]);
        $webhook = WebhookEvent::fromArray($payload);

        $this->assertSame('live', $payload['mode']);
        $this->assertTrue($webhook->isLiveMode());
        $this->assertSame('LTC', $webhook->charge?->asset);
        $this->assertTrue($webhook->charge->isUnderpaid());
        $this->assertStringStartsWith('bc1', $webhook->charge->depositAddress);
    }

    public function test_fake_live_deliveries_are_on_the_main_network(): void
    {
        $live = FakeWebhook::event('charge.paid', payload: ['mode' => 'live'])->charge;
        $test = FakeWebhook::event('charge.paid')->charge;

        $this->assertNotNull($live);
        $this->assertNotNull($test);
        $this->assertStringStartsWith('bc1', $live->depositAddress);
        $this->assertSame('https://mempool.space/tx/'.$live->transactions[0]->txid, $live->transactions[0]->explorerUrl);
        $this->assertStringStartsWith('tb1q', $test->depositAddress);
        $this->assertSame('https://mempool.space/testnet/tx/'.$test->transactions[0]->txid, $test->transactions[0]->explorerUrl);
    }

    public function test_fake_expired_charges_are_past_their_expiry(): void
    {
        foreach (['charge.expired', 'charge.late_payment'] as $type) {
            $expiresAt = FakeWebhook::event($type)->charge?->expiresAt;

            $this->assertNotNull($expiresAt);
            $this->assertLessThan(new DateTimeImmutable, $expiresAt);
        }

        $this->assertGreaterThan(new DateTimeImmutable, FakeWebhook::event('charge.canceled')->charge?->expiresAt);
    }

    public function test_a_fake_late_payment_carries_a_transfer_seen_for_the_first_time(): void
    {
        $webhook = FakeWebhook::event('charge.late_payment');

        $this->assertNotNull($webhook->transaction);
        $this->assertSame(0, $webhook->transaction->confirmations);
        $this->assertFalse($webhook->transaction->credited);
    }

    /**
     * Leave the webhook route to the application.
     *
     * @param  Application  $app
     */
    protected function turnRouteOff($app): void
    {
        $app['config']->set('velirapay.webhook.path', null);
    }
}
