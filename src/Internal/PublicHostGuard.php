<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\Exception\BlockedHostException;

/**
 * The innermost layer of a client after withPublicHostsOnly(), right above the handler, so it sees every attempt
 * of every redirect hop as it is sent. The host must be a public IP address or resolve to one, and the connection
 * is pinned to that address with CURLOPT_CONNECT_TO: cURL never resolves the host itself, so a second DNS answer
 * cannot swap in another address, and a pooled connection to another address is not reused.
 *
 * @internal
 */
final class PublicHostGuard
{
    /**
     * @param callable(string): mixed $resolver
     * @param array<array-key, string> $redactions See Redactor::redactions()
     */
    public static function middleware(callable $resolver, #[\SensitiveParameter] array $redactions): callable
    {
        return static fn(callable $handler): callable => static function (
            #[\SensitiveParameter] RequestInterface $request,
            #[\SensitiveParameter] array $options
        ) use (
            $handler,
            $resolver,
            $redactions
        ) {
            $host = $request->getUri()->getHost();
            if ($host === '') {
                // A relative URI without base_uri: Guzzle's handlers refuse it with the same exception class.
                throw new RequestException('The request URI has no host', $request);
            }

            $literal = IpAddress::fromHost($host);
            $addresses = match (true) {
                $literal !== null => [$literal],
                // An IPv6 address with a zone, or an IPvFuture literal: neither names an address to check.
                str_starts_with($host, '[') => [],
                default => self::resolve($resolver, $host, $options['force_ip_resolve'] ?? null),
            };

            if ($addresses === []) {
                // Reported like cURL's own lookup failure: a NetworkException, retried as a connect failure.
                return Create::rejectionFor(new ConnectException("Could not resolve host: {$host}", $request));
            }

            foreach ($addresses as $address) {
                if (IpAddress::isPublic($address)) {
                    $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
                    // An empty host and port match whatever the request names, so cURL connects to this address.
                    $curl[\CURLOPT_CONNECT_TO] = [str_contains($address, ':') ? "::[{$address}]:" : "::{$address}:"];
                    $options['curl'] = $curl;

                    return $handler($request, $options);
                }
            }

            // Thrown rather than returned, so retries and the log line, which wait on the promise, skip it.
            throw self::refusal($request, $host, $literal, $addresses[0], $redactions);
        };
    }

    /**
     * The default resolver: the A and then the AAAA records of the host, asked from DNS. The hosts file is not
     * read, and a failed lookup counts as no records.
     *
     * @return list<string>
     */
    public static function dns(string $host): array
    {
        $addresses = [];
        foreach ([\DNS_A => 'ip', \DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_string($record[$field] ?? null)) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return $addresses;
    }

    /**
     * @param callable(string): mixed $resolver
     * @return list<string> Addresses in canonical form and in the resolver's order, narrowed to the family
     *     that force_ip_resolve asks for
     */
    private static function resolve(callable $resolver, string $host, mixed $family): array
    {
        $resolved = $resolver($host);
        if (!is_array($resolved)) {
            throw new \UnexpectedValueException('The host resolver must return a list of IP addresses');
        }

        $addresses = [];
        foreach ($resolved as $address) {
            $address = is_string($address) ? IpAddress::normalize($address) : null;
            if ($address === null) {
                throw new \UnexpectedValueException('The host resolver must return a list of IP addresses');
            }

            $ipv6 = str_contains($address, ':');
            if (($family === 'v4' && $ipv6) || ($family === 'v6' && !$ipv6)) {
                continue;
            }

            $addresses[] = $address;
        }

        return $addresses;
    }

    /**
     * @param array<array-key, string> $redactions
     */
    private static function refusal(
        #[\SensitiveParameter] RequestInterface $request,
        string $host,
        ?string $literal,
        string $address,
        #[\SensitiveParameter] array $redactions
    ): BlockedHostException {
        $reason = match (true) {
            $literal === null => "{$host} resolves to {$address}, which is not a public address",
            $literal === trim($host, '[]') => "{$host} is not a public address",
            default => "{$host} is {$address}, which is not a public address",
        };

        return new BlockedHostException(
            $request,
            'HTTP request refused: ' . Redactor::secrets($reason, $redactions),
            $host,
            $address
        );
    }
}
