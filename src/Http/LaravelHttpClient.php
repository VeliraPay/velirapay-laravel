<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends the VeliraPay client's requests through Laravel's HTTP client, so Http::fake() and its other tools apply to them.
 */
final class LaravelHttpClient implements ClientInterface
{
    /**
     * Create a new HTTP client.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly float $timeout,
    ) {
        //
    }

    /**
     * Send a request and return its response, whatever its status.
     *
     * @throws NetworkException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        $pending = $this->http->withHeaders($headers)->withOptions([
            'timeout' => $this->timeout,
            'connect_timeout' => min(10.0, $this->timeout),
        ]);

        $body = (string) $request->getBody();

        if ($body !== '') {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/json');
        }

        try {
            return $pending->send($request->getMethod(), (string) $request->getUri())->toPsrResponse();
        } catch (ConnectionException $exception) {
            throw new NetworkException($exception->getMessage(), $request, $exception);
        } catch (RequestException $exception) {
            return $exception->response->toPsrResponse();
        }
    }
}
