<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use VeliraPay\Laravel\Facades\VeliraPay;
use VeliraPay\Laravel\VeliraPayServiceProvider;

/**
 * The base for the package's tests, with test credentials configured.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The signing secret the webhook tests use.
     */
    protected const WEBHOOK_SECRET = 'whsec_2b7Qf9LkXw3NcR8vT1mZ5pYs0HdJ4gUe6AoKiVbE';

    /**
     * Get the package's service providers.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VeliraPayServiceProvider::class];
    }

    /**
     * Get the package's facades.
     *
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['VeliraPay' => VeliraPay::class];
    }

    /**
     * Configure the package with test credentials.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('velirapay.api_key', 'vp_test_secret');
        $app['config']->set('velirapay.webhook.secret', self::WEBHOOK_SECRET);
    }
}
