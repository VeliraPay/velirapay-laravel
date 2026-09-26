# Changelog

All notable changes to this package are documented here. It follows [Semantic Versioning](https://semver.org).

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
