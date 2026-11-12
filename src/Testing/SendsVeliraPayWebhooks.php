<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Testing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use VeliraPay\Enums\EventType;
use VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature;
use VeliraPay\Webhooks\Webhook;

/**
 * Sends signed VeliraPay webhooks to the application under test.
 */
trait SendsVeliraPayWebhooks
{
    /**
     * Send a signed webhook delivery to the application, as VeliraPay would.
     *
     * @param  array<string, mixed>  $object  Attributes to set on the charge or invoice, with those of a payout delivery's payout under "payout", or on a verification challenge's data, such as "challenge".
     * @param  array<string, mixed>  $payload  Attributes to set on the payload itself, such as "mode" or "test".
     * @param  string|null  $uri  Where to send it; the package's webhook route when null.
     * @return TestResponse<Response>
     *
     * @throws LogicException
     */
    protected function postVeliraPayWebhook(EventType|string $type, array $object = [], array $payload = [], ?string $uri = null): TestResponse
    {
        $config = app(Repository::class);
        $path = $config->get('velirapay.webhook.path');
        $path = is_string($path) ? trim($path, '/') : '';

        if ($uri === null && $path === '') {
            throw new LogicException('The VeliraPay webhook route is turned off: pass the URI of your own route to postVeliraPayWebhook().');
        }

        $secret = VerifyWebhookSignature::secrets($config)[0] ?? null;

        if ($secret === null) {
            $config->set('velirapay.webhook.secret', $secret = 'whsec_testing');
        }

        $delivery = FakeWebhook::payload($type, $object, $payload);
        $body = json_encode($delivery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call('POST', $uri ?? '/'.$path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'VeliraPay-Webhooks/1.0',
            'HTTP_X_VELIRAPAY_SIGNATURE' => Webhook::signatureHeader($body, $secret),
            'HTTP_X_VELIRAPAY_EVENT' => is_string($delivery['event'] ?? null) ? $delivery['event'] : '',
            'HTTP_X_VELIRAPAY_DELIVERY' => is_string($delivery['id'] ?? null) ? $delivery['id'] : '',
        ], content: $body);
    }
}
