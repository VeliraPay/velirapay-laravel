<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use VeliraPay\VeliraPayClient;

/**
 * The VeliraPay API client, configured from the application's config.
 *
 * @method static \VeliraPay\Services\AccountService account()
 * @method static \VeliraPay\Services\ChargeService charges()
 * @method static \VeliraPay\Services\PaymentLinkService paymentLinks()
 * @method static \VeliraPay\Services\InvoiceService invoices()
 * @method static \VeliraPay\Services\EventService events()
 * @method static \VeliraPay\Enums\Mode|null mode()
 * @method static bool isLiveMode()
 * @method static bool isTestMode()
 * @method static \VeliraPay\Http\ApiResponse|null lastResponse()
 * @method static \VeliraPay\Http\ApiResponse request(string $method, string $path, array<string, mixed> $params = [], string|null $idempotencyKey = null)
 *
 * @see VeliraPayClient
 */
final class VeliraPay extends Facade
{
    /**
     * Get the name of the component the facade stands for.
     */
    protected static function getFacadeAccessor(): string
    {
        return VeliraPayClient::class;
    }
}
