# Changelog

All notable changes to this package are documented here. It follows [Semantic Versioning](https://semver.org).

## Unreleased

- The webhook route answers the `webhook.verification` challenge with the PHP library's `challengeResponse()`, and a challenge that carries no value with a 400 rather than an empty answer.
- A blank `VELIRAPAY_WEBHOOK_TOLERANCE`, `VELIRAPAY_TIMEOUT` or `VELIRAPAY_MAX_RETRIES` keeps its default. The config file cast them, so a blank one read as 0, which accepted a webhook signature of any age, sent API requests with no time limit, or turned retries off. Only an explicit `0` turns the age check or retries off, and a timeout of 0 or less, or one that is not a number, is 30 seconds. A negative retry count, or one that is not a number, is 2, where it threw an `InvalidArgumentException`. **If you published `config/velirapay.php`, remove the `(int)` and `(float)` casts from it**: a published file keeps them, so a blank tolerance or retry count still reads as 0 there, while the timeout is now safe either way.
- Fake charges carry `network_surcharge` and `payment_request`, null, as every charge VeliraPay delivers does, and their fields come in the same order as VeliraPay's.
- `FakeWebhook` and `postVeliraPayWebhook()` build the `webhook.verification` challenge VeliraPay sends an endpoint, with a random `challenge` or the one given as `['challenge' => '…']`, so a test can check that a route of your own answers it. They built a pending charge without a challenge.
- A fake payout delivery takes its payout's attributes under `payout`, such as `['payout' => ['network_fee' => '0.000007']]`. They landed on the charge.
- `VELIRAPAY_MAX_RETRY_AFTER` (`velirapay.max_retry_after`), the longest `Retry-After` the client waits out before trying again, 10 seconds by default. A longer one is thrown at once, as before. A published config file needs the line added to change it.

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
