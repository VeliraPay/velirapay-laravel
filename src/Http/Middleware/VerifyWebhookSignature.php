<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use VeliraPay\Exceptions\InvalidArgumentException;
use VeliraPay\Exceptions\SignatureVerificationException;
use VeliraPay\Webhooks\Webhook;

/**
 * Rejects webhook deliveries that were not signed with one of the configured secrets.
 */
final class VerifyWebhookSignature
{
    /**
     * Create a new middleware.
     */
    public function __construct(private readonly Repository $config)
    {
        //
    }

    /**
     * Let the delivery through only when its signature is valid.
     *
     * @param  Closure(Request): SymfonyResponse  $next
     *
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $secrets = self::secrets($this->config);

        if ($secrets === []) {
            throw new InvalidArgumentException('Set VELIRAPAY_WEBHOOK_SECRET in your .env file to the signing secret of your endpoint, under Developers > Webhooks in the VeliraPay dashboard.');
        }

        $tolerance = $this->config->get('velirapay.webhook.tolerance');
        $failure = null;

        foreach ($secrets as $secret) {
            try {
                Webhook::verifySignature(
                    $request->getContent(),
                    $request->headers->get(Webhook::SIGNATURE_HEADER),
                    $secret,
                    is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE,
                );

                return $next($request);
            } catch (SignatureVerificationException $exception) {
                $failure = $exception;
            }
        }

        return new Response('Invalid VeliraPay signature: '.$failure->getMessage(), 403, ['Content-Type' => 'text/plain']);
    }

    /**
     * Get the configured signing secrets.
     *
     * @return list<string>
     */
    public static function secrets(Repository $config): array
    {
        $secrets = $config->get('velirapay.webhook.secret');
        $secrets = is_array($secrets) ? $secrets : explode(',', is_string($secrets) ? $secrets : '');

        return array_values(array_filter(
            array_map(static fn (mixed $secret): string => is_string($secret) ? trim($secret) : '', $secrets),
            static fn (string $secret): bool => $secret !== '',
        ));
    }
}
