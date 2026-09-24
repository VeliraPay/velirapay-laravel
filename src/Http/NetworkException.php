<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown when Laravel's HTTP client could not reach the API.
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface
{
    /**
     * Create a new exception.
     */
    public function __construct(string $message, private readonly RequestInterface $request, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the request that could not be sent.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
