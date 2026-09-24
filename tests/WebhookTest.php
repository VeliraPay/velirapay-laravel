<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use VeliraPay\Exceptions\InvalidArgumentException;
use VeliraPay\Laravel\Events\ChargeCanceled;
use VeliraPay\Laravel\Events\ChargeCreated;
use VeliraPay\Laravel\Events\ChargeExpired;
use VeliraPay\Laravel\Events\ChargeLatePayment;
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Events\ChargePaymentDetected;
use VeliraPay\Laravel\Events\ChargeRefunded;
use VeliraPay\Laravel\Events\ChargeUnderpaid;
use VeliraPay\Laravel\Events\InvoiceCreated;
use VeliraPay\Laravel\Events\InvoicePaid;
use VeliraPay\Laravel\Events\InvoiceSent;
use VeliraPay\Laravel\Events\InvoiceViewed;
use VeliraPay\Laravel\Events\InvoiceVoided;
use VeliraPay\Laravel\Events\WebhookReceived;
use VeliraPay\Laravel\Http\Controllers\WebhookController;
use VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature;
use VeliraPay\Laravel\Testing\FakeWebhook;
use VeliraPay\Webhooks\Webhook;

final class WebhookTest extends TestCase
{
    public function test_a_signed_charge_webhook_is_dispatched_as_events(): void
    {
        Event::fake();
        $payload = FakeWebhook::payload('charge.paid', ['metadata' => ['order_id' => '42']]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET))
            ->assertOk()
            ->assertSee('Webhook handled.');

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->webhook->id === $payload['id']);
        Event::assertDispatched(ChargePaid::class, fn (ChargePaid $event): bool => $event->webhook->type === 'charge.paid'
            && $event->charge->isPaid()
            && $event->charge->metadata === ['order_id' => '42']);
        Event::assertDispatchedTimes(ChargePaid::class, 1);
        Event::assertNotDispatched(ChargeExpired::class);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function types(): iterable
    {
        yield 'charge.created' => ['charge.created', ChargeCreated::class];
        yield 'charge.payment_detected' => ['charge.payment_detected', ChargePaymentDetected::class];
        yield 'charge.paid' => ['charge.paid', ChargePaid::class];
        yield 'charge.underpaid' => ['charge.underpaid', ChargeUnderpaid::class];
        yield 'charge.late_payment' => ['charge.late_payment', ChargeLatePayment::class];
        yield 'charge.refunded' => ['charge.refunded', ChargeRefunded::class];
        yield 'charge.expired' => ['charge.expired', ChargeExpired::class];
        yield 'charge.canceled' => ['charge.canceled', ChargeCanceled::class];
        yield 'invoice.created' => ['invoice.created', InvoiceCreated::class];
        yield 'invoice.sent' => ['invoice.sent', InvoiceSent::class];
        yield 'invoice.viewed' => ['invoice.viewed', InvoiceViewed::class];
        yield 'invoice.paid' => ['invoice.paid', InvoicePaid::class];
        yield 'invoice.voided' => ['invoice.voided', InvoiceVoided::class];
    }

    /**
     * @param  class-string  $event
     */
    #[DataProvider('types')]
    public function test_each_type_has_its_own_event(string $type, string $event): void
    {
        Event::fake();
        $body = json_encode(FakeWebhook::payload($type), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET))->assertOk();

        Event::assertDispatched($event);
    }

    public function test_a_delivery_signed_with_another_secret_is_rejected(): void
    {
        Event::fake();
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, 'whsec_other'))
            ->assertForbidden()
            ->assertSee('Invalid VeliraPay signature: The signature does not match the payload.');

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        Event::fake();

        $this->deliver(json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR), null)
            ->assertForbidden()
            ->assertSee('header is missing');

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_any_of_several_secrets_is_accepted(): void
    {
        config(['velirapay.webhook.secret' => 'whsec_live, '.self::WEBHOOK_SECRET]);
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET))->assertOk();
    }

    public function test_the_signature_age_limit_comes_from_the_config(): void
    {
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);
        $header = Webhook::signatureHeader($body, self::WEBHOOK_SECRET, time() - 600);

        $this->deliver($body, $header)->assertForbidden();

        config(['velirapay.webhook.tolerance' => 900]);

        $this->deliver($body, $header)->assertOk();
    }

    public function test_a_dashboard_test_delivery_only_fires_webhook_received(): void
    {
        Event::fake();
        $body = json_encode(FakeWebhook::payload('charge.paid', payload: ['test' => true, 'event_id' => null]), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET))->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->webhook->test);
        Event::assertNotDispatched(ChargePaid::class);
    }

    public function test_a_type_this_package_does_not_know_only_fires_webhook_received(): void
    {
        Event::fake();
        $body = json_encode(FakeWebhook::payload('charge.disputed'), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET))->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->webhook->type === 'charge.disputed');
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertNotDispatched(ChargePaid::class);
    }

    public function test_a_missing_secret_is_a_configuration_error(): void
    {
        $this->withoutExceptionHandling();
        config(['velirapay.webhook.secret' => '']);
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Set VELIRAPAY_WEBHOOK_SECRET');

        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET));
    }

    public function test_the_route_runs_only_the_signature_check(): void
    {
        $route = Route::getRoutes()->getByName('velirapay.webhook');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame([VerifyWebhookSignature::class], $route->gatherMiddleware());
    }

    #[DefineEnvironment('useCustomPath')]
    public function test_the_route_listens_on_the_configured_path(): void
    {
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);
        $header = Webhook::signatureHeader($body, self::WEBHOOK_SECRET);

        $this->deliver($body, $header, '/hooks/crypto')->assertOk();
        $this->deliver($body, $header)->assertNotFound();
    }

    #[DefineEnvironment('turnRouteOff')]
    public function test_the_route_can_be_turned_off(): void
    {
        $this->assertFalse(Route::has('velirapay.webhook'));
    }

    #[DefineEnvironment('turnRouteOff')]
    public function test_the_controller_verifies_signatures_on_a_route_of_your_own(): void
    {
        Route::post('payments/webhook', WebhookController::class);
        $body = json_encode(FakeWebhook::payload('charge.paid'), JSON_THROW_ON_ERROR);

        $this->deliver($body, Webhook::signatureHeader($body, 'whsec_other'), '/payments/webhook')->assertForbidden();
        $this->deliver($body, Webhook::signatureHeader($body, self::WEBHOOK_SECRET), '/payments/webhook')->assertOk();
    }

    /**
     * Listen for webhooks on a path of the application's choosing.
     *
     * @param  Application  $app
     */
    protected function useCustomPath($app): void
    {
        $app['config']->set('velirapay.webhook.path', '/hooks/crypto/');
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

    /**
     * Post a raw webhook delivery.
     *
     * @return TestResponse<Response>
     */
    private function deliver(string $body, ?string $signature, string $uri = '/velirapay/webhook'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_VELIRAPAY_SIGNATURE'] = $signature;
        }

        return $this->call('POST', $uri, server: $server, content: $body);
    }
}
