<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use VeliraPay\Enums\EventType;
use VeliraPay\Laravel\Events\ChargeCanceled;
use VeliraPay\Laravel\Events\ChargeCreated;
use VeliraPay\Laravel\Events\ChargeEvent;
use VeliraPay\Laravel\Events\ChargeExpired;
use VeliraPay\Laravel\Events\ChargeLatePayment;
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Events\ChargePaymentDetected;
use VeliraPay\Laravel\Events\ChargeRefunded;
use VeliraPay\Laravel\Events\ChargeUnderpaid;
use VeliraPay\Laravel\Events\InvoiceCreated;
use VeliraPay\Laravel\Events\InvoiceEvent;
use VeliraPay\Laravel\Events\InvoicePaid;
use VeliraPay\Laravel\Events\InvoiceSent;
use VeliraPay\Laravel\Events\InvoiceViewed;
use VeliraPay\Laravel\Events\InvoiceVoided;
use VeliraPay\Laravel\Events\WebhookReceived;
use VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * Receives VeliraPay's webhooks and dispatches them as events.
 */
final class WebhookController implements HasMiddleware
{
    /**
     * The event dispatched for each type of charge webhook.
     *
     * @var array<string, class-string<ChargeEvent>>
     */
    private const CHARGE_EVENTS = [
        EventType::ChargeCreated->value => ChargeCreated::class,
        EventType::ChargePaymentDetected->value => ChargePaymentDetected::class,
        EventType::ChargePaid->value => ChargePaid::class,
        EventType::ChargeUnderpaid->value => ChargeUnderpaid::class,
        EventType::ChargeLatePayment->value => ChargeLatePayment::class,
        EventType::ChargeRefunded->value => ChargeRefunded::class,
        EventType::ChargeExpired->value => ChargeExpired::class,
        EventType::ChargeCanceled->value => ChargeCanceled::class,
    ];

    /**
     * The event dispatched for each type of invoice webhook.
     *
     * @var array<string, class-string<InvoiceEvent>>
     */
    private const INVOICE_EVENTS = [
        EventType::InvoiceCreated->value => InvoiceCreated::class,
        EventType::InvoiceSent->value => InvoiceSent::class,
        EventType::InvoiceViewed->value => InvoiceViewed::class,
        EventType::InvoicePaid->value => InvoicePaid::class,
        EventType::InvoiceVoided->value => InvoiceVoided::class,
    ];

    /**
     * Get the middleware the controller runs behind, wherever its route is registered.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware(VerifyWebhookSignature::class)];
    }

    /**
     * Dispatch a verified delivery as WebhookReceived and, unless it is a dashboard test, as its own event.
     */
    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $webhook = WebhookEvent::fromPayload($request->getContent());

        $events->dispatch(new WebhookReceived($webhook));

        if ($webhook->test) {
            return self::handled();
        }

        if ($webhook->charge !== null && isset(self::CHARGE_EVENTS[$webhook->type])) {
            $event = self::CHARGE_EVENTS[$webhook->type];
            $events->dispatch(new $event($webhook, $webhook->charge));
        } elseif ($webhook->invoice !== null && isset(self::INVOICE_EVENTS[$webhook->type])) {
            $event = self::INVOICE_EVENTS[$webhook->type];
            $events->dispatch(new $event($webhook, $webhook->invoice));
        }

        return self::handled();
    }

    /**
     * Build the response that tells VeliraPay the delivery arrived.
     */
    private static function handled(): Response
    {
        return new Response('Webhook handled.', 200, ['Content-Type' => 'text/plain']);
    }
}
