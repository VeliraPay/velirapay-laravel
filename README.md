# VeliraPay for Laravel

[![Tests](https://github.com/VeliraPay/velirapay-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/VeliraPay/velirapay-laravel/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/velirapay/velirapay-laravel)](https://packagist.org/packages/velirapay/velirapay-laravel)
[![License](https://img.shields.io/packagist/l/velirapay/velirapay-laravel)](LICENSE)

The official Laravel integration for [VeliraPay](https://velirapay.com), the crypto payment processor. It sets up the [VeliraPay PHP library](https://github.com/VeliraPay/velirapay-php) from your `.env`, adds a `VeliraPay` facade, and turns VeliraPay's webhooks into Laravel events once their signature is checked.

- [Requirements](#requirements)
- [Installation](#installation)
- [Calling the API](#calling-the-api)
- [Webhooks](#webhooks)
- [Testing](#testing)
- [Configuration](#configuration)

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13

## Installation

```bash
composer require velirapay/velirapay-laravel
```

Add your API key, from the dashboard under **Developers → API keys**, to `.env`:

```dotenv
VELIRAPAY_API_KEY=vp_test_...
```

Keys starting with `vp_test_` work in test mode, on test networks; switch to a `vp_live_` key in production.

To change more than the `.env` variables below allow, publish the config file:

```bash
php artisan vendor:publish --tag=velirapay-config
```

## Calling the API

Use the facade, or type-hint `VeliraPay\VeliraPayClient` to have it injected:

```php
use VeliraPay\Laravel\Facades\VeliraPay;

$charge = VeliraPay::charges()->create([
    'amount' => '49.90',
    'currency' => 'EUR',
    'asset' => 'BTC',
    'customer' => [
        'email' => $request->user()->email,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ],
    'metadata' => ['order_id' => (string) $order->id],
], idempotencyKey: "order-{$order->id}");

return redirect()->away($charge->checkoutUrl);
```

The `customer` details help you spot fraud: the charge is created by your server, so VeliraPay only knows the customer's IP address and browser if you pass them. They come back as `$charge->customer`, along with the reference, phone, country and metadata you may also pass. Charges started on the hosted checkout record the customer's IP address and browser themselves.

```php
use VeliraPay\VeliraPayClient;

public function show(VeliraPayClient $velirapay, string $id)
{
    $charge = $velirapay->charges->retrieve($id);
}
```

The client covers charges, payment links, invoices, events and your account. The [VeliraPay PHP library's README](https://github.com/VeliraPay/velirapay-php#charges) documents every method, the errors they throw and how retries work.

Requests go through Laravel's HTTP client, so they appear in Telescope and Pulse, and `Http::fake()` works on them in tests.

## Webhooks

### Setting up the endpoint

The package registers a `POST /velirapay/webhook` route. Add it as an endpoint in the dashboard under **Developers → Webhooks**:

- Enter the exact URL deliveries should reach, such as `https://example.com/velirapay/webhook`: HTTPS, your site's canonical host (the one it redirects to, with or without `www`) and no trailing slash. VeliraPay does not follow redirects, so a delivery answered with a 3xx counts as failed.
- Tick every event type your application listens for. Only those are sent.
- Localhost and private addresses are refused. To receive webhooks on your machine while developing, put a tunnel in front of it and register the tunnel's URL.

Copy the endpoint's signing secret to `.env`:

```dotenv
VELIRAPAY_WEBHOOK_SECRET=whsec_...
```

The route sits outside the `web` middleware group, so CSRF protection does not get in the way. Every delivery's signature is checked against the secret; deliveries that fail get a 403 and never reach your code.

Test and live mode have separate endpoints, each with its own secret. To send both to the same application, list both secrets, separated by a comma:

```dotenv
VELIRAPAY_WEBHOOK_SECRET=whsec_live...,whsec_test...
```

The application then hears about test payments as well, so check `$event->webhook->isLiveMode()` in your listeners: a test delivery must never fulfil a live order.

Rotating the secret in the dashboard replaces it at once. From then on, deliveries and their retries are signed with the new secret only, and fail the signature check until `VELIRAPAY_WEBHOOK_SECRET` holds it. Replace the old secret there, keeping any other secrets listed, and the failed deliveries go through on their next retry.

### Listening for events

Each webhook is dispatched as an event carrying the charge or invoice:

| Webhook | Event | |
| --- | --- | --- |
| `charge.created` | `ChargeCreated` | A charge was opened and its rate locked. |
| `charge.payment_detected` | `ChargePaymentDetected` | A transfer was seen on-chain and is gathering confirmations. |
| `charge.paid` | `ChargePaid` | The payment is confirmed. Fulfil the order. |
| `charge.underpaid` | `ChargeUnderpaid` | A confirmed payment fell short of your tolerance. |
| `charge.late_payment` | `ChargeLatePayment` | Funds arrived after the charge expired or was canceled, or on top of a charge already paid. |
| `charge.refunded` | `ChargeRefunded` | You recorded that funds were sent back to the customer. |
| `charge.expired` | `ChargeExpired` | The rate lock ran out before the customer paid. |
| `charge.canceled` | `ChargeCanceled` | The charge was canceled from the dashboard or the API, or replaced when the customer switched coin or its invoice was voided. |
| `invoice.created` | `InvoiceCreated` | An invoice was issued. |
| `invoice.sent` | `InvoiceSent` | The invoice, or a reminder of it, was emailed to the customer. |
| `invoice.viewed` | `InvoiceViewed` | The customer opened the invoice for the first time. |
| `invoice.paid` | `InvoicePaid` | One of the invoice's charges was paid, which settles it. |
| `invoice.voided` | `InvoiceVoided` | The invoice was withdrawn and can no longer be paid. |

The classes live in `VeliraPay\Laravel\Events`. Charge events have `$event->charge`, invoice events `$event->invoice`, and all of them `$event->webhook`, the delivery itself. `ChargePaymentDetected` and `ChargeLatePayment` also have `$event->transaction`, the transfer that was seen: its `txid`, `amount`, `confirmations`, `requiredConfirmations` and `explorerUrl`. Both are sent when the transfer is first seen, usually before it has any confirmations. On `ChargePaymentDetected`, tell the customer their payment is on its way, but wait for `ChargePaid` to fulfil. A late payment to an expired or canceled charge waits for you to accept it in the dashboard, which sends `ChargePaid`; one on top of a paid charge is counted automatically. `WebhookReceived` is also dispatched for every delivery, including types added to VeliraPay after this package was released.

A listener in `app/Listeners` is picked up by Laravel automatically:

```php
namespace App\Listeners;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use VeliraPay\Laravel\Events\ChargePaid;

class FulfilOrder implements ShouldQueue
{
    public function handle(ChargePaid $event): void
    {
        $order = Order::findOrFail($event->charge->metadata['order_id']);

        if ($order->isPaid()) {
            return;
        }

        $order->markAsPaid($event->charge->id);
    }
}
```

Some things to know:

- **Deliveries can repeat.** A retry, or a resend from the dashboard's delivery log, keeps the delivery's `$event->webhook->id`, but every endpoint gets a delivery of its own. `$event->webhook->eventId` is the same in all of them, and is the event's id in the events API. Make listeners safe to run twice, as above, or remember `$event->webhook->eventId ?? $event->webhook->id` and skip repeats; test deliveries have no `eventId`.
- **Deliveries can arrive out of order.** A retried `ChargePaymentDetected` can come after `ChargePaid`. When the order matters, read the current state with `VeliraPay::charges()->retrieve($event->charge->id)` rather than trusting the payload.
- **A paid invoice sends two events.** `ChargePaid`, for the charge that paid it (its `$event->charge->invoice` is the invoice's id), and `InvoicePaid`: fulfil on only one of them.
- **Queue slow work.** VeliraPay waits 15 seconds for an answer. A listener that throws turns the answer into a 500, so VeliraPay tries again later. Implement `ShouldQueue` for anything that takes time.
- **Test deliveries.** The dashboard's "Send test event" button sends a sample `charge.paid`, whatever types the endpoint subscribes to, with `$event->webhook->test` set and no `eventId`. It dispatches `WebhookReceived` only, so your `ChargePaid` listeners never run on the sample charge.
- **Amounts are strings.** Coin amounts in webhooks are not trimmed, such as `"0.000400000000000000"` where the API says `"0.0004"`, and fiat amounts read like `"49.90"`. Compare them with `bccomp()`, never as strings or floats.
- **Simulated payments.** A payment simulated in test mode sends `ChargePaid`, or `ChargeUnderpaid`, without a `ChargePaymentDetected` first, and its transfer's `explorerUrl` is null.
- **Refunds.** `ChargeRefunded` carries the charge, whose `refundedAmount` is the total refunded so far, but not the refund itself. Read its amount, and its txid and reason when you gave them, from `VeliraPay::events()->retrieve($event->webhook->eventId)->details`.

### Retries and failures

A delivery succeeds when your application accepts the connection within 5 seconds and answers with a 2xx status within 15. Anything else is a failure, including a redirect, which VeliraPay does not follow. A delivery is tried up to 14 times over about three days: again after 1 minute, 5 minutes, 30 minutes, 1 hour, 2, 4 and 8 hours, then every 10 hours.

When a delivery runs out of attempts, the account's owners and admins are emailed, at most once a day per endpoint. After 15 failed deliveries in a row, the endpoint is switched off and they are told. Switching it back on in the dashboard does not send what it missed: read those events with `VeliraPay::events()->list()`.

### Using your own route

Set `VELIRAPAY_WEBHOOK_PATH` to change the path, or to an empty value to register the route yourself. The controller checks signatures wherever its route is:

```php
use VeliraPay\Laravel\Http\Controllers\WebhookController;

Route::post('payments/velirapay', WebhookController::class);
```

To handle deliveries in a controller of your own, put the `VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature` middleware in front of it and read the delivery with `VeliraPay\Webhooks\WebhookEvent::fromPayload($request->getContent())`.

Either way, keep the route out of CSRF protection, or every delivery is answered with a 419. A route in `routes/web.php` gets it from the `web` middleware group (`PreventRequestForgery` on Laravel 13, `ValidateCsrfToken` on 12), and your tests will not show it, since Laravel skips the check while running them. Register the route in `routes/api.php` instead, where its URL starts with `/api`, or exclude its path in `bootstrap/app.php`, which works on Laravel 12 and 13:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['payments/velirapay']);
})
```

## Testing

### API calls

Fake the API with `Http::fake()`. Fields you leave out of a response take empty values, so give only the ones your code reads:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.velirapay.com/v1/charges' => Http::response([
        'data' => ['id' => 'k3v9x2m7q8wz', 'status' => 'pending', 'checkout_url' => 'https://velirapay.com/c/k3v9x2m7q8wz'],
    ], 201),
]);

$this->post('/checkout')->assertRedirect('https://velirapay.com/c/k3v9x2m7q8wz');

Http::assertSent(fn ($request) => $request['currency'] === 'EUR');
```

`Http::preventStrayRequests()` makes sure no test calls the real API.

### Webhooks

The `SendsVeliraPayWebhooks` trait posts signed deliveries to your application, shaped like the ones VeliraPay sends:

```php
use VeliraPay\Enums\EventType;
use VeliraPay\Laravel\Testing\SendsVeliraPayWebhooks;

class CheckoutTest extends TestCase
{
    use SendsVeliraPayWebhooks;

    public function test_a_paid_charge_fulfils_the_order(): void
    {
        $order = Order::factory()->create();

        $this->postVeliraPayWebhook(EventType::ChargePaid, [
            'metadata' => ['order_id' => (string) $order->id],
        ])->assertOk();

        $this->assertTrue($order->fresh()->isPaid());
    }
}
```

The second argument sets attributes on the charge or invoice, the third on the delivery itself, such as `['mode' => 'live']`. With no secret configured, the trait sets one for the test. The charge is in the state its event leaves it in, with the customer's details filled in, and its address and explorer links are on the network of the delivery's mode. A `charge.payment_detected` or `charge.late_payment` delivery also carries the charge's last transfer, unconfirmed, as `$event->transaction`, and a paid invoice lists the charge that paid it. With `['test' => true]`, the delivery is the sample `charge.paid` the dashboard's "Send test event" button sends, with no `eventId`.

To test a listener on its own, build a delivery with `FakeWebhook`:

```php
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Testing\FakeWebhook;

$webhook = FakeWebhook::event('charge.paid', ['metadata' => ['order_id' => '42']]);

(new FulfilOrder)->handle(new ChargePaid($webhook, $webhook->charge));
```

## Configuration

| `.env` variable | Default | |
| --- | --- | --- |
| `VELIRAPAY_API_KEY` | | Your secret API key. |
| `VELIRAPAY_WEBHOOK_SECRET` | | Your endpoint's signing secret, or several separated by commas. |
| `VELIRAPAY_WEBHOOK_PATH` | `velirapay/webhook` | Where the webhook route listens; empty to register it yourself. |
| `VELIRAPAY_WEBHOOK_TOLERANCE` | `300` | How many seconds old a signature may be; `0` turns the check off. |
| `VELIRAPAY_TIMEOUT` | `30` | How many seconds an API request may take. |
| `VELIRAPAY_MAX_RETRIES` | `2` | How many times a failed API request is tried again. |
| `VELIRAPAY_BASE_URL` | `https://api.velirapay.com` | The address of the VeliraPay API. |

`php artisan about` shows whether the key and secret are set, the key's mode, the base URL and the webhook URL.

## License

MIT. See [LICENSE](LICENSE).
