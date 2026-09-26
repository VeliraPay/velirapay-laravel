<?php

declare(strict_types=1);

namespace VeliraPay\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use VeliraPay\Exceptions\InvalidArgumentException;
use VeliraPay\Laravel\Http\Controllers\WebhookController;
use VeliraPay\Laravel\Http\LaravelHttpClient;
use VeliraPay\Laravel\Http\Middleware\VerifyWebhookSignature;
use VeliraPay\VeliraPayClient;

/**
 * Registers the VeliraPay client and the webhook route.
 */
final class VeliraPayServiceProvider extends ServiceProvider
{
    /**
     * The version of this package.
     */
    public const VERSION = '0.1.1';

    /**
     * Register the VeliraPay client.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/velirapay.php', 'velirapay');

        $this->app->scoped(VeliraPayClient::class, static function (Application $app): VeliraPayClient {
            $config = $app->make(Repository::class);
            $apiKey = $config->get('velirapay.api_key');

            if (! is_string($apiKey) || trim($apiKey) === '') {
                throw new InvalidArgumentException('Set VELIRAPAY_API_KEY in your .env file to a key from the VeliraPay dashboard, under Developers > API keys.');
            }

            $timeout = $config->get('velirapay.timeout');
            $maxRetries = $config->get('velirapay.max_retries');
            $baseUrl = $config->get('velirapay.base_url');
            $factory = new HttpFactory;

            return new VeliraPayClient(
                apiKey: $apiKey,
                httpClient: new LaravelHttpClient($app->make(Factory::class), is_numeric($timeout) ? (float) $timeout : 30.0),
                requestFactory: $factory,
                streamFactory: $factory,
                baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : VeliraPayClient::DEFAULT_BASE_URL,
                maxRetries: is_numeric($maxRetries) ? (int) $maxRetries : 2,
                appInfo: 'VeliraPay-Laravel/'.self::VERSION.' Laravel/'.$app->version(),
            );
        });

        $this->app->alias(VeliraPayClient::class, 'velirapay');
    }

    /**
     * Register the webhook route and the publishable config.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/velirapay.php' => $this->app->configPath('velirapay.php'),
            ], 'velirapay-config');

            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('VeliraPay', fn (): array => $this->about());
            }
        }

        $path = $this->webhookPath();

        if ($path !== null && ! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            $this->app->make(Registrar::class)->post($path, WebhookController::class)->name('velirapay.webhook');
        }
    }

    /**
     * Get the path the webhook route listens on, or null when it is turned off.
     */
    private function webhookPath(): ?string
    {
        $path = $this->app->make(Repository::class)->get('velirapay.webhook.path');

        return is_string($path) && trim($path, '/') !== '' ? trim($path, '/') : null;
    }

    /**
     * Describe the configuration for the "about" command.
     *
     * @return array<string, string>
     */
    private function about(): array
    {
        $config = $this->app->make(Repository::class);
        $apiKey = $config->get('velirapay.api_key');
        $path = $this->webhookPath();

        return [
            'Version' => self::VERSION,
            'API key' => match (true) {
                ! is_string($apiKey) || trim($apiKey) === '' => '<fg=yellow;options=bold>NOT SET</>',
                str_starts_with($apiKey, 'vp_live_') => 'Live',
                str_starts_with($apiKey, 'vp_test_') => 'Test',
                default => '<fg=red;options=bold>UNRECOGNISED</>',
            },
            'Webhook secret' => VerifyWebhookSignature::secrets($config) === [] ? '<fg=yellow;options=bold>NOT SET</>' : '<fg=green;options=bold>SET</>',
            'Webhook URL' => $path === null ? 'Not registered' : $this->app->make(UrlGenerator::class)->to($path),
        ];
    }
}
