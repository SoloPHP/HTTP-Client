<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * withMaxResponseSize() stopped a response whose body is larger than the limit: by its Content-Length as soon as
 * the headers arrived, or once the body went past the limit. The refusal is not retried.
 */
class ResponseTooLargeException extends RequestException
{
    public function __construct(RequestInterface $request, private readonly int $limit)
    {
        parent::__construct($request, "HTTP request failed: the response body exceeds the limit of {$limit} bytes");
    }

    /**
     * The limit in bytes that the response went past.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }
}
