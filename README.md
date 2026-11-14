# VeliraPay for Laravel

[![Tests](https://github.com/VeliraPay/velirapay-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/VeliraPay/velirapay-laravel/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/velirapay/velirapay-laravel)](https://packagist.org/packages/velirapay/velirapay-laravel)
[![License](https://img.shields.io/packagist/l/velirapay/velirapay-laravel)](LICENSE)

The official Laravel integration for [VeliraPay](https://velirapay.com), the crypto payment processor. It sets up the [VeliraPay PHP library](https://github.com/VeliraPay/velirapay-php) from your `.env`, adds a `VeliraPay` facade over the whole API, turns VeliraPay's webhooks into Laravel events once their signature is checked, and sends signed webhooks to your application in tests.

- [Requirements](#requirements)
- [Installation](#installation)
- [Calling the API](#calling-the-api)
  - [Charges](#charges)
  - [Short and late payments](#short-and-late-payments)
  - [Invoices, payment links and coupons](#invoices-payment-links-and-coupons)
  - [Payouts and payout addresses](#payouts-and-payout-addresses)
  - [Events and webhook endpoints](#events-and-webhook-endpoints)
  - [Your account, members, API keys and Pro](#your-account-members-api-keys-and-pro)
  - [Overview and activity](#overview-and-activity)
  - [Lists and pagination](#lists-and-pagination)
  - [Constants](#constants)
  - [Errors, retries and idempotency](#errors-retries-and-idempotency)
  - [Test mode](#test-mode)
- [Webhooks](#webhooks)
- [Testing](#testing)
- [Configuration](#configuration)

## Requirements

- PHP 8.2 or later
- Laravel 12, or Laravel 13 with PHP 8.3 or later

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

Use the facade, or type-hint `VeliraPay\VeliraPayClient` to have the client injected. Each group of endpoints is a method of the facade, such as `VeliraPay::charges()`, and a property of the client, such as `$velirapay->charges`:

```php
use VeliraPay\VeliraPayClient;

public function show(VeliraPayClient $velirapay, string $id)
{
    $charge = $velirapay->charges->retrieve($id);
}
```

Requests go through Laravel's HTTP client, so they appear in Telescope and Pulse, and `Http::fake()` works on them in tests. They do not follow redirects: if you override `VELIRAPAY_BASE_URL`, use the API's final address.

What follows covers what most applications need. The [VeliraPay PHP library's README](https://github.com/VeliraPay/velirapay-php#readme) documents every method and field.

### Charges

To charge a customer, create a charge with what you already know about them and send them to its checkout page:

```php
use VeliraPay\Laravel\Facades\VeliraPay;

$order->increment('payment_attempts');

$charge = VeliraPay::charges()->create([
    'amount' => '49.90',
    'currency' => 'EUR',
    'asset' => 'BTC',
    'description' => "Order #{$order->id}",
    'customer' => [
        'email' => $request->user()->email,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ],
    'metadata' => ['order_id' => (string) $order->id],
    'success_url' => route('orders.show', $order),
    'cancel_url' => route('cart'),
], idempotencyKey: "order-{$order->id}-attempt-{$order->payment_attempts}");

return redirect()->away($charge->checkoutUrl ?? abort(502));
```

`checkoutUrl` is nullable because the charges in webhooks leave it out; a charge the API answers with always has one.

The idempotency key makes the call safe to repeat: sent again with the same key, the same request gets the same charge rather than a second one. Reuse a key only to retry the same request. For 24 hours the API answers a repeated key with the first charge, even once it has expired, or with a 409 when the body differs, which the library throws as a `ConflictException`. So a new charge for the same order, after the first one expired or in another coin, needs a new key, which `payment_attempts` gives it: a counter on the order, raised each time the customer starts paying again.

`success_url` and `cancel_url` must be `https://` addresses on a public host name, or the charge is refused with a `ValidationException`. Leave them out while you develop on `http://localhost`, or use your tunnel's address; a site Herd or Valet serves over HTTPS, such as `https://shop.test`, works too.

`coupon` takes one of your coupon codes off the price: `$charge->fiatAmount` is then the lower price and `$charge->discountAmount` what the coupon took off. `send_email` emails the customer the payment page, as `VeliraPay::charges()->send($charge->id)` does later.

Do not create a new payment link for each customer or each order. A payment link is one reusable public page that many customers pay through; an invoice suits a bill to pay later, or a customer who should choose the coin on its page. A charge needs its coin up front, so ask the customer, and offer the coins your account takes, with the lowest price each can be charged at:

```php
$coins = VeliraPay::assets()->list(['currency' => 'EUR']);

$offered = collect($coins->data)->where('accepted', true); // each with ->id, ->name, ->network and ->minimumPrice
$coins->currencies; // every currency prices can be asked in
```

No charge, payment link or invoice asks for less than the lowest price of its currency: 0.50 in USD, EUR, GBP and CHF, 0.70 in CAD, 0.80 in AUD and 25.00 in TRY. A coin with a minimum of its own asks for more, such as USDT and USDC on Ethereum, which start at 10 of the token, so check its `minimumPrice`. Below either, `create()` throws a `ValidationException`.

A coin such as USDT on Tron asks the customer for a fixed network fee on top of the price, 2.50 USDT at present; each coin's `networkSurcharge` in `VeliraPay::assets()->list()` says how much, so tell the customer before they choose it. A charge in such a coin counts it in `assetAmount` and gives it again as `$charge->networkSurcharge`, while `fiatAmount` stays the price. VeliraPay keeps it: it is not part of your payout, and a refund never includes it.

A charge in `BTC_LIGHTNING` is paid with a Lightning invoice rather than to an address. The checkout page shows it; to show it on a page of your own, `$charge->paymentRequest` is the BOLT11 invoice, for a `lightning:` link or a QR code, and `$charge->depositAddress` its payment hash. `paymentRequest` is null for every other coin.

The `customer` details help you spot fraud: the charge is created by your server, so VeliraPay only knows the customer's IP address and browser if you pass them. They come back as `$charge->customer`, along with the reference, phone, country and metadata you may also pass. Charges started on the hosted checkout record the customer's IP address and browser themselves.

To help you spot bots and people hiding behind servers, VeliraPay also says whose network the IP address is on: `$charge->customer->ipNetwork` names who runs it, such as `"Hetzner Online GmbH"`, and `ipAsn` gives its number, such as `24940`, for any charge with an IP address. A customer on a hosting company's network, rather than an internet provider's, often is one. `ipCountry` is the country the IP address is in, or `"T1"` for Tor, for a charge started on a payment link or an invoice, and `null` for one created through the API. All three are `null` when not known, and webhooks carry them too.

### Short and late payments

```php
VeliraPay::charges()->accept($charge->id);

VeliraPay::charges()->recordRefund($charge->id, [
    'amount' => '0.0005',
    'txid' => '7d1c5e9a…',
    'reason' => 'Returned item',
]);

VeliraPay::charges()->cancel($charge->id); // only while it is pending and no transfer to it has been seen
```

A charge whose confirmed payment fell short of your tolerance is underpaid, and money that reaches a charge after it expired or was canceled, or tops up an underpaid charge after its rate lock ended, is a late payment. Both wait for you to accept them, in the dashboard or with `accept()`, which settles the charge for what arrived and sends `ChargePaid`. It throws a `ConflictException` while a payment to an underpaid charge is still confirming. A late payment on top of a charge already paid is counted automatically.

TRX and USDT on Tron are paid into the same kind of address, and customers sometimes send the one they were not asked for. A charge that expired or was canceled with nothing of its own coin while the other arrived is quoted again in what arrived, once at most: the same charge, with the same id and `fiatAmount`, now has the new coin's `asset`, `assetAmount`, `exchangeRate` and `networkSurcharge`, and `$charge->get('switched_from')` keeps the first quote, its `asset`, `asset_amount`, `exchange_rate` and `switched_at`. It is `null` for every other charge. The transfer waits as a late payment, and once you accept it, `ChargePaid` and the payout follow in the new coin.

`recordRefund()` records a refund you sent from your own wallet; VeliraPay never sends money back itself. An underpaid charge or a late payment is accepted first, and the network surcharge is never refundable.

### Invoices, payment links and coupons

```php
use VeliraPay\Enums\Currency;
use VeliraPay\Enums\PricingType;

$invoice = VeliraPay::invoices()->create([
    'customer_name' => 'Grace Hopper',
    'customer_email' => 'grace@example.com',
    'currency' => Currency::USD,
    'items' => [
        ['description' => 'Consulting', 'quantity' => '10', 'unit_amount' => '100.00'],
    ],
    'due_at' => now()->addDays(14),
    'success_url' => 'https://example.com/orders/1042', // offered once they have paid
    'cancel_url' => 'https://example.com/checkout', // offered while it is open
    'send_email' => true,
]);

$invoice->hostedUrl; // where the customer pays, in the coin of their choice

$link = VeliraPay::paymentLinks()->create([
    'title' => 'Pro plan',
    'pricing_type' => PricingType::Fixed,
    'amount' => '150.00',
    'currency' => Currency::EUR,
]);

$link->checkoutUrl; // one page for every customer

VeliraPay::coupons()->create([
    'code' => 'SPRING10',
    'percent_off' => '10',
    'payment_links' => [$link->id],
    'expires_at' => now()->endOfMonth(),
]);
```

Customers type a coupon's code on a payment link's checkout, `?coupon=SPRING10` on the link's address fills it in, and `charges()->create()` takes it as `coupon`; invoices take none. An invoice's `due_at` is sent as the day it falls on, and a coupon's or payment link's `expires_at` as the same moment in UTC, whatever your application's time zone. An invoice can be emailed once every 10 minutes, and an account sends at most 100 emails to customers an hour, or 10 until one of its owner's accounts has been paid in live mode; past either, a `RateLimitException` says how long to wait.

### Payouts and payout addresses

```php
use VeliraPay\Enums\AddressNetwork;

$payout = VeliraPay::payouts()->retrieve($charge->id); // by the charge's id, once it is paid

$payout->status;     // "pending", "sending", "sent", "failed" or "manual"
$payout->netAmount;  // what you are owed, before the chain's fee
$payout->sentAmount; // what reached your address
$payout->payoutTxid;

foreach (VeliraPay::payouts()->summary() as $coin) {
    $coin->asset;
    $coin->owedAmount; // what has not left yet
}

foreach (VeliraPay::payoutAddresses()->list() as $address) {
    $address->network; // "bitcoin", "evm", …
    $address->address; // null while there is none: payouts in its coins wait for one
}

VeliraPay::payoutAddresses()->update(AddressNetwork::Bitcoin, ['address' => 'bc1q…']);
```

Payouts go out on their own, and nothing here can raise, redirect or reverse one. There is one payout address per network family: `evm` serves Ethereum and its tokens, while BNB Chain, Base, Arbitrum One and Polygon each have their own, and their coins are on offer in live mode only. Changing an address needs the sensitive `payout_addresses:write` permission and an owner switching on "Let API keys and AI apps change payout addresses" under Payment settings, which `VeliraPay::account()->retrieve()->apiPayoutAddressChanges` reads. Owners and admins are emailed about every change, and once the account has been paid out, payouts to a new address wait 48 hours.

`payouts()->summary()` and `payoutAddresses()->list()` return plain arrays, so the meta their answers carry is read from the last response: `VeliraPay::lastResponse()?->meta()['mode']` after either, and `['api_payout_address_changes']` after `payoutAddresses()->list()`.

### Events and webhook endpoints

```php
use VeliraPay\Enums\EventType;

$events = VeliraPay::events()->list(['charge' => $charge->id]);

$event = VeliraPay::events()->retrieve($eventId);
$event->details; // what was recorded, such as the amount and txid of a payment or a refund

$endpoint = VeliraPay::webhookEndpoints()->create([
    'url' => 'https://example.com/velirapay/webhook',
    'events' => [EventType::ChargePaid, EventType::ChargeLatePayment],
]);

$endpoint->secret; // "whsec_…": only this answer carries it, so store it now
```

Adding or changing endpoints needs the sensitive `webhook_endpoints:write` permission. A new endpoint is sent its verification challenge at once, which the package's route answers. Adding an endpoint, giving one a new `url`, `verify()`, `test()` and `retryDelivery()` each send the endpoint a request, so together they are limited to 10 a minute per key.

The signing secret is shown once, in the answer that adds the endpoint or rotates its secret, and never kept for a retry. When that answer is lost, the library sends the request again under the same idempotency key, and the API answers with a `ConflictException` whose `wasReplayed()` is `true`: the endpoint was added, or its secret rotated, but the secret is gone. Find a new endpoint with `webhookEndpoints()->list()` and rotate its secret to get one you can read. After a lost rotation, rotate again and put the new secret in `VELIRAPAY_WEBHOOK_SECRET` straight away, since the one it holds stops signing:

```php
use VeliraPay\Exceptions\ConflictException;

try {
    $secret = VeliraPay::webhookEndpoints()->rotateSecret($endpoint->id)->secret;
} catch (ConflictException $exception) {
    if (! $exception->wasReplayed()) {
        throw $exception;
    }

    $secret = VeliraPay::webhookEndpoints()->rotateSecret($endpoint->id)->secret;
}
```

### Your account, members, API keys and Pro

```php
use VeliraPay\Enums\ApiKeyPermission;
use VeliraPay\Enums\Asset;
use VeliraPay\Enums\CheckoutAppearance;
use VeliraPay\Enums\Currency;
use VeliraPay\Enums\MemberRole;

$account = VeliraPay::account()->retrieve();
$account->acceptedAssets; // the coins it takes right now, in the key's mode
$account->isPro();

VeliraPay::account()->update(['checkout' => ['appearance' => CheckoutAppearance::Dark]]);
VeliraPay::account()->updatePaymentSettings(['default_currency' => Currency::EUR]);

VeliraPay::invitations()->create(['email' => 'grace@example.com', 'role' => MemberRole::Finance]);
VeliraPay::members()->update($memberId, ['role' => MemberRole::Support]);

$apiKey = VeliraPay::apiKeys()->create([
    'name' => 'Shop server',
    'permissions' => [ApiKeyPermission::ChargesRead, ApiKeyPermission::ChargesWrite],
]);
$apiKey->key; // "vp_live_…", shown this once

$payment = VeliraPay::billing()->startProPayment(['asset' => Asset::BTC]); // with a live key
$payment->checkoutUrl; // where to pay a month of Pro
```

Full-access keys can read the overview and payout addresses and start a Pro payment. Reading the activity log, API keys, members and invitations, or changing account or payment settings, payout addresses, webhook endpoints, API keys and members, needs the corresponding sensitive permission explicitly ticked on a restricted key. So does reading the account's business details: for a key without `account:write`, every field of `VeliraPay::account()->retrieve()->business` is `null`. A key can make or change no key that reaches further than itself, only a key an owner made changes a role or removes a member, and owners are never changed this way.

A new API key is shown once and never kept for a retry. When the answer to `apiKeys()->create()` is lost, the retry throws a `ConflictException` whose `wasReplayed()` is `true`: the key was made, but nobody can read it. Find it with `apiKeys()->list()`, revoke it with `apiKeys()->revoke()`, and create another. Two keys may share a name, so give the key a name no other key has, such as one with a random suffix, or the search may revoke a key you still use.

### Overview and activity

```php
$overview = VeliraPay::overview()->retrieve(); // the dashboard's last 30 days

$overview->volume;                      // in $overview->currency
$overview->conversionRatePercent;       // the share of paid, expired and canceled charges that were paid
$overview->funnel->abandoned;           // attempts to pay that ended before anything arrived
$overview->attention->underpaidCharges; // what needs a person now
$overview->daily;                       // every day of the period, oldest first

foreach (VeliraPay::activity()->list(['from' => now()->subWeek()]) as $entry) {
    $entry->action; // such as "api_key.created"
    $entry->actor;
}
```

The overview also has the period it covers, the coins and payment links paid most and which steps of setting up the account are done. Reading the activity log needs the sensitive `activity:read` permission, and its `from` and `to` filters are sent as the days they fall on.

### Lists and pagination

```php
use Illuminate\Support\LazyCollection;
use VeliraPay\Enums\ChargeStatus;

$page = VeliraPay::charges()->list(['status' => ChargeStatus::Paid, 'per_page' => 100]);

$page->total;
$page->hasMore();
$page->nextPage(); // null on the last page

$paid = LazyCollection::make(fn () => VeliraPay::charges()->iterator(['status' => ChargeStatus::Paid]));
```

A paged `list()` returns one page, newest first (members oldest first), of up to 100 objects, 25 unless you pass `per_page`. `iterator()` walks every page, fetching each one as it is reached, which `LazyCollection::make()` turns into a lazy collection. A page keeps its answer's meta as `$page->meta`, and `$page->mode` is the key's mode for the coins on offer and the activity log. `assets()->list()` is a single page holding every coin, and the payout summary and payout addresses are plain arrays.

### Constants

Each set of values the API uses is an enum in `VeliraPay\Enums`, and every parameter that takes one of these values takes its case as well as the string:

| Enum | Values |
| --- | --- |
| `Asset` | The coins, each on its network, such as `Asset::BTC` or `Asset::USDT_TRON` |
| `Currency` | `USD`, `EUR`, `GBP`, `CAD`, `AUD`, `CHF` and `TRY` |
| `ChargeStatus`, `InvoiceStatus`, `PaymentLinkStatus`, `PayoutStatus` | The states of charges, invoices, payment links and payouts |
| `EventType` | The webhooks and events, such as `EventType::ChargePaid` |
| `PricingType` | `fixed` and `open` |
| `WebhookDeliveryStatus` | `pending`, `succeeded` and `failed` |
| `MemberRole` | `owner`, `admin`, `finance`, `support` and `viewer`; `MemberRole::assignable()` gives the four an invitation or a role change can give |
| `AddressNetwork` | The network families of payout addresses, such as `AddressNetwork::Evm` |
| `ApiKeyPermission` | What a restricted API key may do, such as `ApiKeyPermission::ChargesRead` |
| `ActivityAction` | What the activity log records, such as `ActivityAction::ApiKeyCreated` |
| `CheckoutAppearance`, `CheckoutLocale` | `auto`, `light` and `dark`, and the checkout's languages |
| `PlanName`, `AccountType` | `free` and `pro`, and `personal` and `organization` |
| `Mode` | `live` and `test` |

`charges()->create()` takes its `currency` as a string, so pass `Currency::EUR->value` there. What the API sends back stays a string, so a value added after your version of the library still reads: compare with the case's value, as in `$charge->status === ChargeStatus::Paid->value`, or use helpers such as `$charge->isPaid()`. `FakeWebhook` and `postVeliraPayWebhook()` take the cases too.

### Errors, retries and idempotency

A failed request throws an exception from `VeliraPay\Exceptions` carrying the API's message: `AuthenticationException` (401), `PermissionException` (403, naming what the key lacks), `NotFoundException` (404, also for an object of the other mode), `ConflictException` (409), `ValidationException` (422), `RateLimitException` (429), `ServerException` (5xx), or `ConnectionException` when the API could not be reached. All of them implement `VeliraPayException`, and the [PHP library's README](https://github.com/VeliraPay/velirapay-php#errors) says when each is thrown. A `ValidationException`'s `errors()` are keyed by the API's field names, so a form can show them:

```php
use Illuminate\Validation\ValidationException;
use VeliraPay\Exceptions\ValidationException as VeliraPayValidationException;

try {
    $charge = VeliraPay::charges()->create($params);
} catch (VeliraPayValidationException $exception) {
    throw ValidationException::withMessages($exception->errors()); // such as ['amount' => ['Enter at least €0.50.']]
}
```

Network errors, answers cut off after they began, rate limits (429) and outages (502, 503, 504, and Cloudflare's 520 to 524) are tried again, up to `VELIRAPAY_MAX_RETRIES` times, a little later each time or as late as the API's `Retry-After` asks. A `Retry-After` longer than `VELIRAPAY_MAX_RETRY_AFTER` seconds is thrown at once, and the exception's `retryAfter()` says how long to wait. A 500 is retried for reads only.

While retries are on, every write carries an `Idempotency-Key`, so a retried write is carried out once. The library makes one up per call; pass your own as `idempotencyKey:` to make a call safe to repeat across requests or queued jobs, and `VeliraPay::lastResponse()?->wasReplayed()` says whether the API answered from an earlier request with the same key. When the API still cannot be reached after the retries, a write may have gone through all the same, so the `ConnectionException` carries the key it was sent with:

```php
use VeliraPay\Exceptions\ConnectionException;

try {
    $charge = VeliraPay::charges()->create($params);
} catch (ConnectionException $exception) {
    $charge = VeliraPay::charges()->create($params, idempotencyKey: $exception->idempotencyKey);
}
```

For an endpoint the library has no method for yet, send the request yourself: `VeliraPay::request('GET', '/v1/charges', ['per_page' => 5])->json()`.

### Test mode

Keys starting with `vp_test_` work in test mode, on each chain's test network, and see nothing of live mode: a live key asking for a test charge gets a 404, and each mode has its own webhook endpoints. `VeliraPay::isTestMode()` and `VeliraPay::isLiveMode()` read the key's mode, `VeliraPay::mode()` gives it as a `Mode` case, and `php artisan about` shows it.

```php
VeliraPay::charges()->simulate($charge->id);                         // paid without coins, with the webhooks a real payment sends
VeliraPay::charges()->simulate($charge->id, ['amount' => '0.0001']); // less than is due, to try an underpayment
```

BNB Chain, Base, Arbitrum One and Polygon have no test mode, so their coins can only be used with a live key. In test mode, invoices and charges are only emailed to members of your account.

## Webhooks

### Setting up the endpoint

The package registers a `POST /velirapay/webhook` route. Add it as an endpoint in the dashboard under **Developers → Webhooks**:

- Enter the exact URL deliveries should reach, such as `https://example.com/velirapay/webhook`: HTTPS on port 443, a host name rather than an IP address, no user name or password in it, your site's canonical host (the one it redirects to, with or without `www`) and no trailing slash. VeliraPay does not follow redirects, so a delivery answered with a 3xx counts as failed.
- Tick every event type your application listens for. Only those are sent.
- Localhost and private addresses are refused. To receive webhooks on your machine while developing, put a tunnel in front of it and register the tunnel's URL.

Copy the endpoint's signing secret to `.env`:

```dotenv
VELIRAPAY_WEBHOOK_SECRET=whsec_...
```

Then press **Verify** on the endpoint's page. VeliraPay sends the endpoint a signed `webhook.verification` challenge, which the route answers for you, and sends it nothing else until it has. Moving the endpoint to another URL asks for this again.

The route sits outside the `web` middleware group, so CSRF protection does not get in the way. Every delivery's signature is checked against the secret; deliveries that fail get a 403 and never reach your code.

Test and live mode have separate endpoints, each with its own secret. To send both to the same application, list both secrets, separated by a comma:

```dotenv
VELIRAPAY_WEBHOOK_SECRET=whsec_live...,whsec_test...
```

The application then hears about test payments as well, so compare `$event->webhook->isLiveMode()` with your key's mode in your listeners, as below: a test delivery must never fulfil a live order.

Rotating the secret in the dashboard signs deliveries and their retries with the new secret from then on. For a day they also carry a signature made with the old one, so they keep passing the check while `VELIRAPAY_WEBHOOK_SECRET` still holds it. Replace the old secret there within that day, keeping any other secrets listed.

### Listening for events

Each webhook is dispatched as an event carrying the charge or invoice:

| Webhook | Event | |
| --- | --- | --- |
| `charge.created` | `ChargeCreated` | A charge was opened and its rate locked. |
| `charge.payment_detected` | `ChargePaymentDetected` | A transfer was seen on-chain and is gathering confirmations. |
| `charge.paid` | `ChargePaid` | The payment is confirmed. Fulfil the order. |
| `charge.underpaid` | `ChargeUnderpaid` | A confirmed payment fell short of your tolerance. |
| `charge.late_payment` | `ChargeLatePayment` | Funds arrived after the charge expired or was canceled, after an underpaid charge's rate lock ended, or on top of a charge already paid. |
| `charge.refunded` | `ChargeRefunded` | You recorded that funds were sent back to the customer. |
| `charge.expired` | `ChargeExpired` | The rate lock ran out before the customer paid. |
| `charge.canceled` | `ChargeCanceled` | The charge was canceled from the dashboard or the API, or replaced when the customer switched coin or its invoice was voided. |
| `invoice.created` | `InvoiceCreated` | An invoice was issued. |
| `invoice.sent` | `InvoiceSent` | The invoice, or a reminder of it, was emailed to the customer. |
| `invoice.viewed` | `InvoiceViewed` | The customer opened the invoice for the first time. |
| `invoice.paid` | `InvoicePaid` | One of the invoice's charges was paid, which settles it. |
| `invoice.voided` | `InvoiceVoided` | The invoice was withdrawn and can no longer be paid. |
| `payout.sent` | `PayoutSent` | Your share of a paid charge was broadcast and is waiting for the chain to confirm it. |
| `payout.confirmed` | `PayoutConfirmed` | The chain confirmed the payout, so the money has reached your address. |

The classes live in `VeliraPay\Laravel\Events`. Charge events have `$event->charge`, invoice events `$event->invoice`, payout events `$event->charge` and `$event->payout`, and all of them `$event->webhook`, the delivery itself. Book the money on `PayoutConfirmed`: a broadcast payout can still be dropped or fail and go out again, so `PayoutSent` can arrive more than once for a charge. `ChargePaymentDetected` and `ChargeLatePayment` also have `$event->transaction`, the transfer that was seen: its `txid`, `amount`, `confirmations`, `requiredConfirmations` and `explorerUrl`. Both are sent when the transfer is first seen, usually before it has any confirmations, except a top-up to an underpaid charge after its rate lock ended, which is announced as a late payment once it has confirmed. On `ChargePaymentDetected`, tell the customer their payment is on its way, but wait for `ChargePaid` to fulfil. A late payment to an expired, canceled or underpaid charge waits for you to accept it, which sends `ChargePaid`; one on top of a paid charge is counted automatically. `WebhookReceived` is also dispatched for every delivery, including types added to VeliraPay after this package was released.

A listener in `app/Listeners` is picked up by Laravel automatically:

```php
namespace App\Listeners;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use VeliraPay\Laravel\Events\ChargePaid;
use VeliraPay\Laravel\Facades\VeliraPay;

class FulfilOrder implements ShouldQueue
{
    public function handle(ChargePaid $event): void
    {
        // Only payments in your API key's mode, so a test payment never fulfils a live order.
        if ($event->webhook->isLiveMode() !== VeliraPay::isLiveMode()) {
            return;
        }

        // A charge made without your metadata, such as one through a payment link, has none.
        $order = Order::find($event->charge->metadata['order_id'] ?? null);

        if ($order === null || $order->isPaid()) {
            return;
        }

        $order->markAsPaid($event->charge->id);
    }
}
```

Some things to know:

- **Deliveries can repeat.** A retry, or a resend from the dashboard's delivery log, keeps the delivery's `$event->webhook->id`, but every endpoint gets a delivery of its own. `$event->webhook->eventId` is the same in all of them, and is the event's id in the events API. Make listeners safe to run twice, as above, or remember `$event->webhook->eventId ?? $event->webhook->id` and skip repeats; test deliveries have no `eventId`.
- **Deliveries can arrive out of order.** A retried `ChargePaymentDetected` can come after `ChargePaid`. When the order matters, read the current state with `VeliraPay::charges()->retrieve($event->charge->id)` rather than trusting the payload.
- **A paid invoice sends two events.** `ChargePaid`, for the charge that paid it, and `InvoicePaid`: fulfil on only one of them.
- **Payment links and invoices by name.** `$event->charge->paymentLink` and `$event->charge->invoice` are the codes of the payment link a charge was made through and of the invoice it pays, and `$event->charge->paymentLinkTitle` and `$event->charge->invoiceNumber` their title and number, which webhooks carry and the API's answers do not.
- **Queue slow work.** VeliraPay waits 15 seconds for an answer. A listener that throws turns the answer into a 500, so VeliraPay tries again later. Implement `ShouldQueue` for anything that takes time.
- **Test deliveries.** The dashboard's "Send test event" button sends a sample `charge.paid`, whatever types the endpoint subscribes to, with `$event->webhook->test` set and no `eventId`. It dispatches `WebhookReceived` only, so your `ChargePaid` listeners never run on the sample charge.
- **Coupons lower the price.** A customer who used one of your coupons pays less than the full price: `$event->charge->fiatAmount` is the lower price, `$event->charge->coupon` the code and `$event->charge->discountAmount` what it took off, so the full price is `bcadd($event->charge->fiatAmount, $event->charge->discountAmount ?? '0', 2)`. Account for the discount when you compare the amount with your order, and check `fiatCurrency` too.
- **The network surcharge is not the price.** On a coin such as USDT on Tron, `$event->charge->assetAmount` includes `networkSurcharge`, the network fee the customer paid on top of the price, which VeliraPay keeps.
- **Read the coin from each delivery.** A Tron charge paid in the other Tron coin keeps its id but changes `asset` once, so its `ChargeLatePayment` and `ChargePaid` name a coin other than the one it was created with, and `$event->charge->get('switched_from')` holds the first quote. Take `asset` and `assetAmount` from the payload rather than from what you stored when you created it. This is not the charge a customer replaced by picking another coin at checkout, which ends in `ChargeCanceled` while a new charge takes over.
- **Amounts are strings.** A charge's coin amounts and exchange rate, and its transfers' amounts, are not trimmed in webhooks, such as `"0.000400000000000000"` where the API says `"0.0004"`. `$event->payout`'s amounts are trimmed, as in the API, and fiat amounts read like `"49.90"`. Compare them all with `bccomp()`, never as strings or floats.
- **Simulated payments.** A payment simulated in test mode sends `ChargePaid`, or `ChargeUnderpaid`, without a `ChargePaymentDetected` first, and its transfer's `explorerUrl` is null.
- **Refunds.** `ChargeRefunded` carries the charge, whose `refundedAmount` is the total refunded so far, but not the refund itself. Read its amount, and its txid and reason when you gave them, from `VeliraPay::events()->retrieve($event->webhook->eventId)->details`.

### Retries and failures

A delivery succeeds when your application accepts the connection within 5 seconds and answers with a 2xx status within 15. Anything else is a failure, including a redirect, which VeliraPay does not follow. A delivery is tried up to 14 times over about three days: again after 1 minute, 5 minutes, 30 minutes, 1 hour, 2, 4 and 8 hours, then every 10 hours.

When a delivery runs out of attempts, the account's owners and admins are emailed, at most once a day per endpoint. After 15 failed deliveries in a row, the endpoint is switched off and they are told. While it is off, no new events are queued for it and the deliveries still waiting are marked failed. Switching it back on in the dashboard does not send what it missed: resend those deliveries from its delivery log, or read the events with `VeliraPay::events()->list()`.

### Using your own route

Set `VELIRAPAY_WEBHOOK_PATH` to change the path, or to an empty value to register the route yourself. The controller checks signatures and answers the verification challenge wherever its route is:

```php
use VeliraPay\Laravel\Http\Controllers\WebhookController;

Route::post('payments/velirapay', WebhookController::class);
```

To handle deliveries in a controller of your own, put the `VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature` middleware in front of it and read the delivery with `VeliraPay\Webhooks\WebhookEvent::fromPayload($request->getContent())`. Answer a delivery whose `isVerification()` is true with its `challengeResponse()`, as JSON, before anything else.

Either way, keep the route out of CSRF protection, or every delivery is answered with a 419. A route in `routes/web.php` gets it from the `web` middleware group (`PreventRequestForgery` on Laravel 13, `ValidateCsrfToken` on 12), and your tests will not show it, since Laravel skips the check while running them. Register the route in `routes/api.php` instead, where its URL starts with `/api`: Laravel 12 and 13 only load that file once `php artisan install:api` has created it, which also installs Sanctum. Or exclude its path in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestForgery(except: ['payments/velirapay']);
})
```

On Laravel 12, call `validateCsrfTokens(except: ['payments/velirapay'])` instead, which Laravel 13 still accepts but deprecates. If `bootstrap/app.php` already calls `preventRequestForgery()` with `originOnly` or `allowSameSite`, add the path to that call's `except` rather than call it again, since every call sets both anew.

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

`Http::preventStrayRequests()` makes sure no test calls the real API. The client and its services are final classes, so the facade cannot be mocked: `VeliraPay::shouldReceive()` throws. `Http::fake()` covers the facade and code that has the client injected alike.

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

Deliveries are in test mode unless you pass `['mode' => 'live']`, so a listener that compares their mode with your key's, as the one above does, needs a test key in your tests, such as `<env name="VELIRAPAY_API_KEY" value="vp_test_testing"/>` in `phpunit.xml`.

The second argument sets attributes on the charge or invoice, the third on the delivery itself, such as `['mode' => 'live']`; both take the PHP library's enum cases as well as strings. With no secret configured, the trait sets one for the test. The charge is in the state its event leaves it in, with the customer's details filled in, the country and network of their IP address `null`, and its address and explorer links are on the network of the delivery's mode. A `customer` you pass replaces the fake one whole, such as `['customer' => ['ip_address' => '198.51.100.4', 'ip_network' => 'Hetzner Online GmbH']]`. The charge's `processing_terms` are those of a charge quoted today on the Free plan, `['percent' => '1.75', 'max_forwarding_percent' => 10]`, and its `switched_from` is `null`; for a charge quoted again in the coin that arrived, pass the new coin and the first quote, such as `['asset' => Asset::USDT_TRON, 'asset_amount' => '27.500000000000000000', 'exchange_rate' => '1.000000000000000000', 'network_surcharge' => '2.500000000000000000', 'switched_from' => ['asset' => 'TRX', 'asset_amount' => '83.333334000000000000', 'exchange_rate' => '0.300000000000000000', 'switched_at' => '2026-11-14T12:05:49.000000Z']]` on a `charge.late_payment`. A `charge.payment_detected` or `charge.late_payment` delivery also carries the charge's last transfer, unconfirmed, as `$event->transaction`, so set it through the charge's `transactions`. A payout delivery's payout takes its own attributes under `payout`, such as `['payout' => ['network_fee' => '0.000007']]`, and a paid invoice lists the charge that paid it. With `['test' => true]`, the delivery is the sample `charge.paid` the dashboard's "Send test event" button sends, with no `eventId`.

`postVeliraPayWebhook('webhook.verification')` sends the challenge VeliraPay sends a new endpoint, with a random value or the one given as `['challenge' => '…']`, so a test can check that a route of your own answers it. Deliveries go to the package's route; once it is turned off, pass the URI of your own as `uri:`, or the trait throws a `LogicException`.

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
| `VELIRAPAY_WEBHOOK_TOLERANCE` | `300` | How many seconds old a signature may be. Only an explicit `0` turns the check off; a blank value keeps the default. |
| `VELIRAPAY_TIMEOUT` | `30` | How many seconds an API request may take. A blank value, `0` or less keeps the default. |
| `VELIRAPAY_MAX_RETRIES` | `2` | How many times a failed API request is tried again; `0` turns retries off, and a blank value keeps the default. |
| `VELIRAPAY_MAX_RETRY_AFTER` | `10` | The longest `Retry-After`, in seconds, that is waited out before trying again. A longer one is thrown at once, and the exception's `retryAfter()` says how long to wait. |
| `VELIRAPAY_BASE_URL` | `https://api.velirapay.com` | The address of the VeliraPay API. |

`php artisan about` shows whether the key and secret are set, the key's mode, the base URL and the webhook URL.

## License

MIT. See [LICENSE](LICENSE).
