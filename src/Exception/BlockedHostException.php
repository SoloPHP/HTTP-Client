<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * withPublicHostsOnly() refused a request before sending it: its host is not a public IP address and does not
 * resolve to one. getRequest() returns the refused request, the redirect target when a redirect was refused.
 */
class BlockedHostException extends RequestException
{
    public function __construct(
        RequestInterface $request,
        string $message,
        private readonly string $host,
        private readonly string $address
    ) {
        parent::__construct($request, $message);
    }

    /**
     * The host as the request URI names it, such as "intranet.example.com" or "[::1]".
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * The address that is not public: the host itself, or the first address it resolves to.
     */
    public function getAddress(): string
    {
        return $this->address;
    }
}
