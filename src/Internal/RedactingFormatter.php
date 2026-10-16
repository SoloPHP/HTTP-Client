<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\MessageFormatterInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Log line format: "GET https://host/path?key=*** HTTP/1.1 200 1234 [error]".
 *
 * @internal
 */
final class RedactingFormatter implements MessageFormatterInterface
{
    /**
     * @param array<array-key, string> $redactions See Redactor::redactions()
     */
    public function __construct(private readonly array $redactions)
    {
    }

    public function format(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): string {
        $line = sprintf(
            '%s %s HTTP/%s %s',
            $request->getMethod(),
            Redactor::uri($request->getUri(), $this->redactions),
            $request->getProtocolVersion(),
            $response !== null ? (string) $response->getStatusCode() : '-'
        );

        if ($response !== null && $response->hasHeader('Content-Length')) {
            $line .= ' ' . $response->getHeaderLine('Content-Length');
        }

        if ($error !== null) {
            $line .= ' ' . Redactor::message($error->getMessage(), $this->redactions);
        }

        return $line;
    }
}
