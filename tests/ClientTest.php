<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use VeliraPay\Exceptions\ConflictException;
use VeliraPay\Exceptions\ConnectionException;
use VeliraPay\Exceptions\InvalidArgumentException;
use VeliraPay\Laravel\Facades\VeliraPay;
use VeliraPay\Laravel\VeliraPayServiceProvider;
use VeliraPay\VeliraPayClient;

final class ClientTest extends TestCase
{
    public function test_the_client_is_configured_from_the_config(): void
    {
        Http::fake([
            'api.velirapay.com/v1/account' => Http::response([
                'data' => ['id' => 'acme', 'name' => 'Acme'],
                'meta' => ['mode' => 'test', 'accepted_assets' => ['BTC']],
            ]),
        ]);

        $account = VeliraPay::account()->retrieve();

        $this->assertSame('acme', $account->id);
        $this->assertSame(['BTC'], $account->acceptedAssets);
        $this->assertSame(app(VeliraPayClient::class), app('velirapay'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.velirapay.com/v1/account'
            && $request->hasHeader('Authorization', 'Bearer vp_test_secret')
            && str_contains($request->toPsrRequest()->getHeaderLine('User-Agent'), 'VeliraPay-Laravel/'.VeliraPayServiceProvider::VERSION));
    }

    public function test_request_bodies_and_idempotency_keys_are_sent(): void
    {
        Http::fake([
            'api.velirapay.com/v1/charges' => Http::response([
                'data' => ['id' => 'k3v9x2m7q8wz', 'status' => 'pending', 'checkout_url' => 'https://velirapay.com/c/k3v9x2m7q8wz'],
            ], 201),
        ]);

        $charge = VeliraPay::charges()->create(['amount' => '25.00', 'currency' => 'USD', 'asset' => 'BTC'], 'order-42');

        $this->assertSame('https://velirapay.com/c/k3v9x2m7q8wz', $charge->checkoutUrl);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->body() === '{"amount":"25.00","currency":"USD","asset":"BTC"}'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('Idempotency-Key', 'order-42'));
    }

    public function test_api_errors_become_exceptions(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Only pending charges can be canceled.'], 409)]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Only pending charges can be canceled.');

        VeliraPay::charges()->cancel('k3v9x2m7q8wz');
    }

    public function test_an_unreachable_api_is_reported_as_a_network_error(): void
    {
        config(['velirapay.max_retries' => 0]);
        Http::fake(fn () => throw new ConnectException('Could not resolve host', new PsrRequest('GET', 'https://api.velirapay.com/v1/charges')));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not reach the VeliraPay API: Could not resolve host');

        VeliraPay::charges()->list();
    }

    public function test_stray_requests_are_stopped_by_laravel(): void
    {
        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);

        VeliraPay::account()->retrieve();
    }

    public function test_a_missing_api_key_is_explained(): void
    {
        config(['velirapay.api_key' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Set VELIRAPAY_API_KEY in your .env file');

        VeliraPay::account();
    }
}
