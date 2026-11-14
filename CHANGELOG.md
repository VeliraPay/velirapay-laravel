# Changelog

All notable changes to this package are documented here. It follows [Semantic Versioning](https://semver.org).

## 0.6.6 - 2026-11-14

- Requires `velirapay/velirapay-php` 0.6.10, so `$charge->switchedFrom` reads the first quote of a charge VeliraPay quoted again in the coin that arrived (`asset`, `assetAmount`, `exchangeRate`, `switchedAt`), `$charge->processingTerms` the processing fee and forwarding limit fixed when it was quoted (`percent`, `maxForwardingPercent`), and `$payout->merchantNetworkReserve` what a payout set aside from the merchant's share for network costs not known when it went out, and what became of it. The README says what each is for.
- Fake charges carry `processing_terms`, `['percent' => '1.75', 'max_forwarding_percent' => 10]` as on a charge quoted today on the Free plan, and `switched_from`, null, after `network_surcharge`, as the charges VeliraPay delivers now do. The dashboard's sample charge, with `['test' => true]`, still has neither, as VeliraPay's has not.
- Passing `switched_from` with the new coin's `asset`, `asset_amount`, `exchange_rate` and `network_surcharge` fakes a Tron charge quoted again in the other Tron coin, the one that arrived, as VeliraPay now does with a charge that expired or was canceled while only the other coin reached its address.
- The README covers such a charge: it keeps its id and `fiatAmount` but changes coin once, with `$charge->switchedFrom` holding the first quote, its transfer waits as a late payment, and `ChargePaid` follows in the new coin, so a listener reads the coin from each delivery rather than from what it stored at creation.
- Fake payouts match the fake charge's default processing terms, whatever the charge is given: `fee_percent` is `1.75`, the fee `0.000007` of the `0.0004` gross, worth `0.44`, the net `0.000393`, and the chain's fee comes out of the merchant's share, so `sent_amount` is `0.0003886`, as VeliraPay books a Bitcoin payout of a charge quoted today. They kept 3%, as before VeliraPay fixed the rate on each charge. They also carry `merchant_network_reserve`, null, after `network_fee`, as the payouts VeliraPay delivers now do.

## 0.6.5 - 2026-11-12

- Requires `velirapay/velirapay-php` 0.6.9, so `$charge->customer->ipCountry`, `ipAsn` and `ipNetwork` read the country and network of the customer's IP address, and a blank idempotency key counts as none, so the library makes one up rather than sending a blank one the API ignores, under which a retried write could be carried out twice.
- Fake charges' customers carry `ip_country`, `ip_asn` and `ip_network`, null, after `ip_address`, as the customers VeliraPay delivers now do. The README says what `$charge->customer->ipCountry`, `ipAsn` and `ipNetwork` are for, and that a `customer` passed to `FakeWebhook` or `postVeliraPayWebhook()` replaces the fake one whole.

## 0.6.4 - 2026-11-12

- Requires `velirapay/velirapay-php` 0.6.8, so `invoices()->create()` takes `success_url` and `cancel_url`, which the invoice's page and its charges' checkout offer the customer as a way back to your site, and `$invoice->successUrl` and `$invoice->cancelUrl` read them back. The README's invoice example passes both.

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
