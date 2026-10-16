<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * Base for exceptions that carry the failed request (PSR-18 network and request exceptions).
 */
abstract class RequestAwareException extends HttpClientException
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
