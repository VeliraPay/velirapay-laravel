# Changelog

All notable changes to this package are documented here. It follows [Semantic Versioning](https://semver.org).

## 0.6.3 - 2026-11-12

- Requires `velirapay/velirapay-php` 0.6.7, and with it `$charge->networkSurcharge`, `paymentRequest`, `paymentLinkTitle` and `invoiceNumber`, the Lightning, Base, Arbitrum One and Polygon coins, the overview's figures, the meta of list answers, an enum for every set of values the API uses, dates sent in UTC and float amounts as plain decimals, retries of answers cut off after they began and of Cloudflare's 520 to 524, and `ConflictException::wasReplayed()` for a write whose answer carried a new API key or signing secret.
- Requires `guzzlehttp/psr7` 2 or 3, whose `HttpFactory` the client is built with. Laravel 12 allows psr7 1.9, which has none, and there the client could not be created.
- A blank `VELIRAPAY_WEBHOOK_TOLERANCE`, `VELIRAPAY_TIMEOUT` or `VELIRAPAY_MAX_RETRIES` keeps its default. The config file cast them, so a blank one read as 0, which accepted a webhook signature of any age, sent API requests with no time limit, or turned retries off. Only an explicit `0` turns the age check or retries off, and a timeout of 0 or less, or one that is not a number, is 30 seconds. A negative retry count, or one that is not a number, is 2, where it threw an `InvalidArgumentException`. **If you published `config/velirapay.php`, remove the `(int)` and `(float)` casts from it**: a published file keeps them, so a blank tolerance or retry count still reads as 0 there, while the timeout is now safe either way.
- `VELIRAPAY_MAX_RETRY_AFTER` (`velirapay.max_retry_after`), the longest `Retry-After` the client waits out before trying again, 10 seconds by default. A longer one is thrown at once, as before. A published `config/velirapay.php` without the line still reads the variable, so the line is only needed to set the value in that file itself.
- The webhook route answers the `webhook.verification` challenge with the PHP library's `challengeResponse()`, and a challenge that carries no value with a 400 rather than an empty answer.
- Fake charges carry `network_surcharge` and `payment_request`, null, as every charge VeliraPay delivers does, and their fields come in the same order as VeliraPay's.
- `FakeWebhook` and `postVeliraPayWebhook()` build the `webhook.verification` challenge VeliraPay sends an endpoint, with a random `challenge` or the one given as `['challenge' => '…']`, so a test can check that a route of your own answers it. They built a pending charge without a challenge.
- A fake payout delivery takes its payout's attributes under `payout`, such as `['payout' => ['network_fee' => '0.000007']]`. They landed on the charge.
- `FakeWebhook` and `postVeliraPayWebhook()` take the PHP library's enum cases wherever they take a value, such as `['asset' => Asset::LTC]` or `['mode' => Mode::Live]`. A case went into the payload as an object, so the fake charge read it as empty and a live mode given as `Mode::Live` built a test-network charge.
- `postVeliraPayWebhook()` throws a `LogicException` asking for the URI of your own route when the package's route is turned off and none is given. It posted to `/`.
- The README covers the whole API through the facade, as velirapay-php's does: charges with customer details, coupons and return addresses, the lowest price of each currency, the network surcharge of USDT on Tron and the Lightning invoice of a charge, short and late payments, invoices, payment links, coupons, payouts and payout addresses, events and webhook endpoints, the account, members, API keys and Pro, the overview and the activity log, pagination, the constants, errors, retries and idempotency, and test mode.
- The README's charge example keys idempotency on each attempt to pay rather than on the order, which got a returning customer the first, expired charge for 24 hours, or a 409 in another coin. Its listener reads `order_id` as something a charge may lack, and leaves deliveries of the other mode alone. It also says that `success_url` and `cancel_url` must be public `https://` addresses, everything a webhook endpoint's URL must be, that a payout's amounts are trimmed in webhooks while a charge's are not, what `paymentLinkTitle` and `invoiceNumber` hold, that Laravel 13 deprecates `validateCsrfTokens()` for `preventRequestForgery()`, that `routes/api.php` needs `php artisan install:api`, that the facade cannot be mocked with `shouldReceive()`, and how to recover from a lost answer that carried a new API key or signing secret.
- The README's charge example answers 502 when a charge has no `checkoutUrl`, which only the charges in webhooks lack, rather than passing null to `redirect()->away()`, which throws a `TypeError` and which static analysis refuses. The README also gives the smaller hourly allowance of an account whose owner has not been paid in live mode yet: 10 emails to customers rather than 100.
- The README and `ChargeLatePayment`'s docblock cover late payments on underpaid charges: a top-up after the rate lock ended arrives as `ChargeLatePayment` once it confirms and waits to be accepted, accepting answers 409 while a payment to an underpaid charge is still confirming, and a refund needs the charge accepted first and never includes the network surcharge.

## 0.6.2 - 2026-11-09

- Requires `velirapay/velirapay-php` 0.6.5, whose `Asset` enum has USDT and USDC on Polygon and Arbitrum One, and whose docs name the five roles a member can have now that `member` itself is gone.
- The README says that the account's business details are only sent to a key with the sensitive `account:write` permission: for any other key, every field of `VeliraPay::account()->retrieve()->business` is `null`. It also lists changing payment settings among what needs a sensitive permission.

## 0.6.1 - 2026-11-06

- API requests no longer follow redirects, keeping writes on the configured endpoint.
- Fake dashboard test deliveries use `charge.paid`, matching the API.
- Clarified which operations require sensitive permissions.

## 0.6.0 - 2026-11-06

- `VeliraPay::overview()`, `payoutAddresses()`, `activity()`, `apiKeys()`, `members()`, `invitations()` and `billing()`, and the new methods on `VeliraPay::account()` and `VeliraPay::webhookEndpoints()`: changing the account and its payment settings, adding, changing, verifying, testing and deleting webhook endpoints, and managing API keys, members and Pro from your code. Most need a permission ticked on a restricted key.
- Requires velirapay/velirapay-php 0.6.

## 0.5.0 - 2026-11-03

- `PayoutSent` and `PayoutConfirmed` events for the `payout.sent` and `payout.confirmed` webhooks, with `$event->charge` and `$event->payout`. `FakeWebhook` builds both.
- Requires velirapay/velirapay-php 0.5.

## 0.4.0 - 2026-11-03

- `VeliraPay::assets()`, `VeliraPay::payouts()` and `VeliraPay::webhookEndpoints()` for the coins on offer and their minimums, what became of each paid charge, and whether an endpoint can receive deliveries.
- The README starts from the recommended way to charge a customer: a charge, with what you already know about them, rather than a payment link per customer.
- Requires velirapay/velirapay-php 0.4.
- The webhook route answers the `webhook.verification` challenge VeliraPay now sends an endpoint when it is added or its URL changes, which it must answer before it receives anything else. No event is dispatched for it.
- The README covers the challenge, HTTPS-only endpoints, and the old secret's signature that deliveries carry for a day after a rotation.

## 0.3.0 - 2026-10-15

- `VeliraPay::coupons()` for the coupon endpoints.
- Fake charges carry `coupon` and `discount_amount`, null, like the ones VeliraPay sends.
- The README covers charges paid with a coupon and what happens to deliveries while an endpoint is switched off.
- Requires velirapay/velirapay-php 0.3.

## 0.2.0 - 2026-10-07

- `php artisan about` shows the API's base URL.
- Fake test deliveries, with `['test' => true]`, are the sample the dashboard's "Send test event" button sends: a short paid charge and no `event_id`.
- Fake live deliveries use a mainnet address and explorer links.
- Fake `invoice.paid` deliveries list the charge that paid the invoice.
- Fake expired and late-paid charges are past their expiry, and the transfer on a fake `charge.late_payment` has no confirmations yet.
- The README covers the retry schedule, CSRF protection on a route of your own, setting up and rotating an endpoint, live and test deliveries in one application, duplicates, amounts and refunds.
- Works with velirapay/velirapay-php 0.1.1 or 0.2.

## 0.1.1 - 2026-09-26

- `$event->transaction` on `ChargePaymentDetected` and `ChargeLatePayment`: the transfer that was seen on-chain.
- The customer's details on fake charges, and the transfer on fake `charge.payment_detected` and `charge.late_payment` deliveries.
- Requires velirapay/velirapay-php 0.1.1 or later.

## 0.1.0 - 2026-09-24

First release.

- The VeliraPay client, configured from `.env`, in the container and behind a `VeliraPay` facade.
- API requests sent through Laravel's HTTP client, so `Http::fake()` works on them.
- A webhook route that checks signatures against one or more secrets.
- An event for every webhook type, plus `WebhookReceived` for every delivery.
- `SendsVeliraPayWebhooks` and `FakeWebhook` for testing webhook handling.
- VeliraPay's configuration in `php artisan about`.
