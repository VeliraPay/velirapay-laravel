<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\WithEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use VeliraPay\Exceptions\ConflictException;
use VeliraPay\Exceptions\ConnectionException;
use VeliraPay\Exceptions\InvalidArgumentException;
use VeliraPay\Exceptions\RateLimitException;
use VeliraPay\Laravel\Facades\VeliraPay;
use VeliraPay\Laravel\Http\LaravelHttpClient;
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

    public function test_the_facade_reaches_the_coins_on_offer_payouts_and_webhook_endpoints(): void
    {
        $client = app(VeliraPayClient::class);

        $this->assertSame($client->assets, VeliraPay::assets());
        $this->assertSame($client->payouts, VeliraPay::payouts());
        $this->assertSame($client->webhookEndpoints, VeliraPay::webhookEndpoints());
    }

    public function test_the_facade_reaches_the_account_management_endpoints(): void
    {
        $client = app(VeliraPayClient::class);

        $this->assertSame($client->overview, VeliraPay::overview());
        $this->assertSame($client->payoutAddresses, VeliraPay::payoutAddresses());
        $this->assertSame($client->activity, VeliraPay::activity());
        $this->assertSame($client->apiKeys, VeliraPay::apiKeys());
        $this->assertSame($client->members, VeliraPay::members());
        $this->assertSame($client->invitations, VeliraPay::invitations());
        $this->assertSame($client->billing, VeliraPay::billing());
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

    public function test_redirects_are_returned_without_forwarding_the_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.velirapay.com/v1/charges' => Http::response('', 302, ['Location' => 'https://api.velirapay.com/v1/moved']),
            'api.velirapay.com/v1/moved' => Http::response(['data' => ['id' => 'unexpected']], 200),
        ]);
        $client = new LaravelHttpClient(app(Factory::class), 30.0);

        $response = $client->sendRequest(new PsrRequest('POST', 'https://api.velirapay.com/v1/charges'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://api.velirapay.com/v1/moved', $response->getHeaderLine('Location'));
        Http::assertSentCount(1);
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

    public function test_the_timeout_comes_from_the_config(): void
    {
        config(['velirapay.timeout' => '5']);

        $this->assertSame([5.0, 5.0], $this->timeoutsSent());
    }

    #[WithEnv('VELIRAPAY_TIMEOUT', '')]
    public function test_a_blank_timeout_keeps_the_default(): void
    {
        $this->assertSame([30.0, 10.0], $this->timeoutsSent());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'not a number' => ['thirty'];
    }

    #[DataProvider('unusableTimeouts')]
    public function test_an_unusable_timeout_falls_back_to_the_default(mixed $timeout): void
    {
        config(['velirapay.timeout' => $timeout]);

        $this->assertSame([30.0, 10.0], $this->timeoutsSent());
    }

    #[WithEnv('VELIRAPAY_MAX_RETRIES', '')]
    public function test_a_blank_retry_count_keeps_retries_on(): void
    {
        $this->assertTrue($this->writesAreRetried());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableRetryCounts(): iterable
    {
        yield 'negative' => [-1];
        yield 'not a number' => ['two'];
    }

    #[DataProvider('unusableRetryCounts')]
    public function test_an_unusable_retry_count_falls_back_to_the_default(mixed $retries): void
    {
        config(['velirapay.max_retries' => $retries]);

        $this->assertTrue($this->writesAreRetried());
    }

    public function test_retries_can_be_turned_off(): void
    {
        config(['velirapay.max_retries' => 0]);

        $this->assertFalse($this->writesAreRetried());
    }

    public function test_the_longest_retry_after_to_wait_out_comes_from_the_config(): void
    {
        config(['velirapay.max_retry_after' => 0]);
        Http::fake(['*' => Http::response(['message' => 'Too many requests.'], 429, ['Retry-After' => '1'])]);

        try {
            VeliraPay::charges()->list();
            $this->fail('The rate limit was waited out.');
        } catch (RateLimitException $exception) {
            $this->assertSame(1, $exception->retryAfter());
        }

        Http::assertSentCount(1);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableRetryAfters(): iterable
    {
        yield 'blank' => [''];
        yield 'negative' => [-1];
        yield 'not a number' => ['ten'];
    }

    #[DataProvider('unusableRetryAfters')]
    public function test_an_unusable_longest_retry_after_falls_back_to_the_default(mixed $seconds): void
    {
        config(['velirapay.max_retry_after' => $seconds]);

        $this->assertSame(app(VeliraPayClient::class)->charges, VeliraPay::charges());
    }

    /**
     * Send a request through the client, and get the timeout and connect timeout it went with.
     *
     * @return array{mixed, mixed}
     */
    private function timeoutsSent(): array
    {
        $sent = [null, null];

        Http::fake(function (Request $request, array $options) use (&$sent) {
            $sent = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

            return Http::response(['data' => ['id' => 'acme', 'name' => 'Acme']]);
        });

        VeliraPay::account()->retrieve();

        return $sent;
    }

    /**
     * Determine whether the client retries writes, which then carry an idempotency key of the library's own.
     */
    private function writesAreRetried(): bool
    {
        Http::fake(['api.velirapay.com/v1/charges/k3v9x2m7q8wz/cancel' => Http::response([
            'data' => ['id' => 'k3v9x2m7q8wz', 'status' => 'canceled'],
        ])]);

        VeliraPay::charges()->cancel('k3v9x2m7q8wz');

        return Http::recorded(fn (Request $request): bool => $request->hasHeader('Idempotency-Key'))->isNotEmpty();
    }
}
