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
 * Rejects webhook deliveries that were not signed with one of the configured secrets, or with a secret given for the other mode.
 */
final class VerifyWebhookSignature
{
    /**
     * The key of the secrets that sign for either mode.
     */
    private const EITHER_MODE = 'either';

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
        $secrets = self::secretsByMode($this->config);

        if (array_merge(...array_values($secrets)) === []) {
            throw new InvalidArgumentException('Set VELIRAPAY_WEBHOOK_SECRET in your .env file to the signing secret of your endpoint, under Developers > Webhooks in the VeliraPay dashboard.');
        }

        $tolerance = $this->config->get('velirapay.webhook.tolerance');
        $signedFor = [];
        $failure = null;

        foreach ($secrets as $mode => $ofMode) {
            foreach ($ofMode as $secret) {
                try {
                    Webhook::verifySignature(
                        $request->getContent(),
                        $request->headers->get(Webhook::SIGNATURE_HEADER),
                        $secret,
                        is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE,
                    );

                    $signedFor[] = $mode;

                    break;
                } catch (SignatureVerificationException $exception) {
                    $failure = $exception;
                }
            }
        }

        if ($signedFor === []) {
            return new Response('Invalid VeliraPay signature: '.$failure?->getMessage(), 403, ['Content-Type' => 'text/plain']);
        }

        // A secret given for one mode says nothing about the other, so a test secret cannot vouch for a live payment.
        if (! in_array(self::EITHER_MODE, $signedFor, true) && ! in_array(self::modeOf($request), $signedFor, true)) {
            return new Response('Invalid VeliraPay signature: it was made with the secret of the other mode.', 403, ['Content-Type' => 'text/plain']);
        }

        return $next($request);
    }

    /**
     * Get every configured signing secret, those that sign for either mode first.
     *
     * @return list<string>
     */
    public static function secrets(Repository $config): array
    {
        return array_merge(...array_values(self::secretsByMode($config)));
    }

    /**
     * Get the configured signing secrets under the mode each signs for: "live", "test", or either mode for those in velirapay.webhook.secret that were given no mode of their own.
     *
     * @return array{either: list<string>, live: list<string>, test: list<string>}
     */
    public static function secretsByMode(Repository $config): array
    {
        $live = self::listed($config->get('velirapay.webhook.live_secret'));
        $test = self::listed($config->get('velirapay.webhook.test_secret'));

        // A secret given a mode of its own is believed about that mode alone, even while it is still in the older list as well.
        return [
            self::EITHER_MODE => array_values(array_diff(self::listed($config->get('velirapay.webhook.secret')), $live, $test)),
            'live' => $live,
            'test' => $test,
        ];
    }

    /**
     * Get the secrets in a config value: a list, or a string that separates them with commas.
     *
     * @return list<string>
     */
    private static function listed(mixed $secrets): array
    {
        $secrets = is_array($secrets) ? $secrets : explode(',', is_string($secrets) ? $secrets : '');

        return array_values(array_filter(
            array_map(static fn (mixed $secret): string => is_string($secret) ? trim($secret) : '', $secrets),
            static fn (string $secret): bool => $secret !== '',
        ));
    }

    /**
     * Get the mode a delivery says it is of, or null when its body does not say.
     */
    private static function modeOf(Request $request): ?string
    {
        $delivery = json_decode($request->getContent(), true);
        $mode = is_array($delivery) ? ($delivery['mode'] ?? null) : null;

        return is_string($mode) ? $mode : null;
    }
}
