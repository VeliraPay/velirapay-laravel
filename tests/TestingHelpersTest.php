<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use VeliraPay\Enums\EventType;
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Events\InvoiceVoided;
use VeliraPay\Laravel\Testing\FakeWebhook;
use VeliraPay\Laravel\Testing\SendsVeliraPayWebhooks;

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
    }
}
