# Solo HTTP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/http-client.svg)](https://packagist.org/packages/solophp/http-client)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/solophp/http-client.svg)](LICENSE)

Immutable PSR-18 HTTP client for PHP, built on Guzzle 8. Every configuration call returns a new client, so one instance can be shared safely; credentials go only to the origin of the base URI; retries are opt-in and never repeat a POST the server may have processed; files are attached only through explicit `File` objects; URLs that users supply can be kept to public hosts, away from internal networks; query values, URI credentials and registered secrets are masked in logs and exception messages; and parameters that carry secrets stay out of stack traces.

## Installation

```bash
composer require solophp/http-client
```

Requires PHP 8.3+ and Guzzle 8.3+.

## Example

```php
use Solo\HttpClient\HttpClient;

$api = HttpClient::create('https://api.example.com/v1/')
    ->timeout(10)
    ->withToken($token)  // sent to https://api.example.com only
    ->retry(3, 500);     // off unless you ask for it

$response = $api->get('users', ['query' => ['page' => 2]]);

if ($response->successful()) {
    $users = $response->json('data'); // null when the body is not valid JSON
}

$api->post('users', ['json' => ['name' => 'Ada', 'email' => 'ada@example.com']]);
```

HTTP error statuses come back as responses; a failed transfer throws a PSR-18 exception. Every request method has an async variant, and `sendRequest()` serves code that depends on `Psr\Http\Client\ClientInterface`.

## Fetching URLs from users

Webhooks, feed imports and image downloads request URLs that users supply, and such a URL can point inside your network: at a database port on `127.0.0.1`, an admin panel on `10.0.0.5`, or the cloud metadata service at `169.254.169.254` that hands out credentials. `withPublicHostsOnly()` sends requests only to public IP addresses, which blocks this server-side request forgery (SSRF):

```php
use Solo\HttpClient\Exception\BlockedHostException;

$fetcher = HttpClient::create()->timeout(10)->withPublicHostsOnly();

try {
    $image = $fetcher->get($imageUrl)->body();
} catch (BlockedHostException $e) {
    // nothing was sent: $e->getHost() is not a public address and does not resolve to one
}
```

The client resolves the host itself and refuses it unless it is a public address or resolves to one. Every redirect hop and every retry is checked again, and the connection is pinned to the checked address, so a DNS answer that changes after the check (DNS rebinding) cannot send the request elsewhere.

## Documentation

**[Full Documentation](https://solophp.github.io/HTTP-Client/)**

- [Getting Started](https://solophp.github.io/HTTP-Client/guide/)
- [Sharing a Client](https://solophp.github.io/HTTP-Client/guide/#share-one-configured-client)
- [Authentication](https://solophp.github.io/HTTP-Client/guide/#authenticate)
- [Retries](https://solophp.github.io/HTTP-Client/guide/#retry-failed-requests)
- [File Uploads](https://solophp.github.io/HTTP-Client/guide/#upload-files)
- [URLs from Users](https://solophp.github.io/HTTP-Client/guide/#fetch-urls-from-users)
- [Logging and Redaction](https://solophp.github.io/HTTP-Client/guide/#log-requests)
- [Async Requests](https://solophp.github.io/HTTP-Client/guide/#send-requests-concurrently)
- [Error Handling](https://solophp.github.io/HTTP-Client/guide/#handle-errors)
- [Testing with a Mock Handler](https://solophp.github.io/HTTP-Client/guide/#test-code-that-uses-the-client)
- [API Reference](https://solophp.github.io/HTTP-Client/guide/api)
- [Behavior in Detail](https://solophp.github.io/HTTP-Client/guide/details)
- [Upgrading from 4.x](CHANGELOG.md#upgrading-from-4x)
- [Changelog](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE).
