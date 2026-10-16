# Solo HTTP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/http-client.svg)](https://packagist.org/packages/solophp/http-client)
[![License](https://img.shields.io/packagist/l/solophp/http-client.svg)](https://github.com/solophp/http-client/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/http-client.svg)](https://packagist.org/packages/solophp/http-client)

A simple and elegant PSR-18 HTTP client built on top of Guzzle with fluent API.

> Upgrading from 3.x? Version 4.0 fixes several security issues and contains breaking changes —
> see [CHANGELOG.md](CHANGELOG.md#upgrading-from-3x).

## Features

- **PSR-18 Compliant**: Implements `Psr\Http\Client\ClientInterface`
- **Immutable Fluent API**: Every configuration call returns a new instance, safe to share
- **Safe Retry Logic**: Opt-in retries with capped exponential backoff and `Retry-After` support
- **Credentials bound to origin**: Tokens are never sent to foreign hosts
- **Logging Support**: PSR-3 logging with redacted query strings
- **JSON Support**: Easy JSON request/response handling
- **Multipart/Form-Data**: Explicit file uploads, form submissions
- **Async Support**: Asynchronous request methods
- **Middleware Support**: Custom Guzzle middleware and handlers

## Installation

```bash
composer require solophp/http-client
```

## Quick Start

```php
use Solo\HttpClient\HttpClient;

// Create client
$client = HttpClient::create('https://api.example.com')
    ->timeout(30)
    ->withHeaders(['Accept' => 'application/json'])
    ->withToken('your-api-token')
    ->retry(3, 1000);

// Make requests
$response = $client->get('/users');
$users = $response->json();

$response = $client->post('/users', [
    'json' => [
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ]
]);
```

## Basic Usage

### Creating a Client

```php
// Simple client
$client = new HttpClient('https://api.example.com');

// With configuration
$client = new HttpClient('https://api.example.com', [
    'timeout' => 30,
    'headers' => ['User-Agent' => 'MyApp/1.0']
]);

// Using fluent API
$client = HttpClient::create('https://api.example.com')
    ->timeout(60)
    ->withHeaders(['X-Custom' => 'value']);
```

### Immutability

`timeout()`, `retry()` and all `with*()` methods return a **new** instance and never change the current one.
A client stored in a DI container can be shared safely — per-call settings do not leak into other requests,
and all copies share one connection pool:

```php
$shared = HttpClient::create('https://api.example.com');

$shared->withToken($userA->token)->get('/me'); // only this request is authorized
$shared->get('/public');                       // no token here

// Always use the returned instance
$client = $shared->timeout(5);
```

### Making Requests

```php
// GET request
$response = $client->get('/users');

// POST with JSON data
$response = $client->post('/users', [
    'json' => [
        'name' => 'John',
        'email' => 'john@example.com'
    ]
]);

// PUT, PATCH, DELETE, HEAD
$response = $client->put('/users/1', ['json' => ['name' => 'John Updated']]);
$response = $client->patch('/users/1', ['json' => ['email' => 'john.updated@example.com']]);
$response = $client->delete('/users/1');
$response = $client->head('/users/1');

// With custom headers and JSON data
$response = $client->post('/users', [
    'json' => ['name' => 'John'],
    'headers' => ['X-Custom' => 'header']
]);
```

### Multipart/Form-Data Requests

Files are attached **only** with `File` objects. Plain arrays are always sent as text fields, so request data
can be forwarded as is: a user sending `avatar[path]=/etc/passwd` gets a text field, not your server's file.

```php
use Solo\HttpClient\File;

// Simple form fields (nested arrays become "tags[0]", "meta[key]")
$response = $client->postMultipart('/upload', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'tags' => ['php', 'http'],
]);

// Upload file
$response = $client->postMultipart('/upload', [
    'name' => 'John Doe',
    'avatar' => File::fromPath('/path/to/avatar.jpg'),
]);

// Upload file with custom filename and MIME type
$response = $client->postMultipart('/upload', [
    'document' => File::fromPath('/path/to/document.pdf', 'my-document.pdf', 'application/pdf'),
]);

// Upload from a stream or resource
$response = $client->postMultipart('/upload', [
    'report' => File::fromStream(fopen('php://temp', 'r+'), 'report.csv', 'text/csv'),
]);

// PUT and PATCH also available
$response = $client->putMultipart('/users/1', ['avatar' => File::fromPath('/path/to/new-avatar.jpg')]);
$response = $client->patchMultipart('/users/1', ['avatar' => File::fromPath('/path/to/avatar.jpg')]);
```

`File::fromPath()` accepts only regular readable local files. Stream wrappers (`php://`, `http://`, `phar://`,
`data:` ...) are rejected.

Quotes, backslashes and control characters in field names and file names are percent-encoded, as browsers do
(`John's "CV".pdf` is sent as `John's %22CV%22.pdf`), so they can never inject part headers. Invalid MIME types
are rejected. All fields are validated before any file is opened, so a failed call leaves your streams untouched.
The same protection applies to Guzzle's raw `multipart` option on every request method, sync or async.

### Working with Responses

```php
$response = $client->get('/users/1');

// Check status
if ($response->successful()) {
    $user = $response->json();
    echo $user['name'];
}

// Get specific JSON value
$name = $response->json('name');
$email = $response->json('profile.email');

// Get headers
$contentType = $response->header('Content-Type');
$allHeaders = $response->headers();

// Get raw body
$body = $response->body();
```

`json()` returns the decoded value (array, scalar or `null`). If the body is not valid JSON (for example,
an HTML error page from a proxy), it returns `null`, so checks like `if ($response->json('allowed'))` fail closed.

### Authentication

```php
// Bearer token
$client = $client->withToken('your-api-token');

// Custom token type
$client = $client->withToken('your-token', 'Custom');

// Basic auth
$client = $client->withBasicAuth('username', 'password');
```

Credentials set on the client (`withToken()`, `withBasicAuth()`, an `Authorization` header in `withHeaders()`
or in constructor config, the `auth` option including digest and NTLM) are sent **only to the origin of
`base_uri`** (same scheme, host and port; `base_uri` may be a string or a `UriInterface`). Requests to other
hosts — absolute URLs, pagination links from API responses — go without them.

Credentials are added to the initial request only, so they are always dropped on cross-origin redirects.
Without `base_uri` they are sent to every host you request explicitly.

An `Authorization` header passed in per-request options is always sent as is.

### Retry Logic

Retries are **disabled by default**.

```php
$client = $client->retry(3, 1000);          // 3 retries, backoff 1s, 2s, 4s (+ up to 10% jitter)
$client = $client->retry(5, 500, 10000);    // cap each delay at 10 seconds (default cap: 30 seconds)
```

What is retried:

| Method | Network error | 429 | 5xx |
|---|---|---|---|
| GET, HEAD, OPTIONS, TRACE, PUT, DELETE | yes | yes | yes |
| POST, PATCH | only if the request was not sent (DNS, connection refused, TLS handshake) | yes | no |

POST and PATCH are not idempotent: a timeout or 5xx may come after the server has already processed
the request, so a retry could charge a card twice. If your API supports idempotency keys, enable it explicitly:

```php
$client = $client->retry(3, 1000, retryNonIdempotent: true);
$client->post('/payments', ['headers' => ['Idempotency-Key' => $key], 'json' => $payment]);
```

`Retry-After` (seconds or HTTP date) is honored but never exceeds the delay cap; invalid values are ignored.

Requests with a non-seekable body (a stream that can be read only once) are never retried, because the retry
would send an empty body.

### Logging

```php
// Default logging (to error_log)
$client = $client->withLogging();

// Custom callable logger
$client = $client->withLogging(function (string $message) {
    file_put_contents('http.log', $message . PHP_EOL, FILE_APPEND);
});

// PSR-3 Logger integration: "info" for responses, "error" for failed transfers
$client = $client->withLogging($psr3Logger);
```

Log line format: `GET https://api.example.com/users?page=*** HTTP/1.1 200 1234`.
Query values and URI credentials are redacted in logs and exception messages.

Secrets in the URL path cannot be detected automatically — register them with `withRedaction()`:

```php
$client = HttpClient::create('https://api.telegram.org')
    ->withRedaction($botToken)
    ->withLogging($psr3Logger);

$client->post("/bot{$botToken}/sendMessage", ['json' => $message]); // logged as /bot***/sendMessage
```

### Async Requests

```php
$promise = $client->getAsync('/users');
$response = $promise->wait();

$promise = $client->postAsync('/users', ['json' => ['name' => 'John']]);
$response = $promise->wait();

// Also: headAsync(), putAsync(), patchAsync(), deleteAsync(), requestAsync(), sendAsync()
```

### Custom Requests

```php
// Custom method with options
$response = $client->request('POST', '/custom', [
    'json' => ['data' => 'value'],
    'headers' => ['X-Custom' => 'header'],
    'timeout' => 10
]);

// Async custom request
$promise = $client->requestAsync('PUT', '/custom', [
    'json' => ['data' => 'value']
]);

// PSR-18
$psrResponse = $client->sendRequest(new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/users'));
```

## Error Handling

HTTP error statuses (4xx, 5xx) do not throw. Transfer errors throw exceptions that implement PSR-18
interfaces and extend `RuntimeException`; `NetworkException` and `RequestException` also provide `getRequest()`:

| Exception | When |
|---|---|
| `Solo\HttpClient\Exception\NetworkException` | DNS, connection, timeout (`NetworkExceptionInterface`) |
| `Solo\HttpClient\Exception\RequestException` | Other transfer errors (`RequestExceptionInterface`) |
| `Solo\HttpClient\Exception\HttpClientException` | Base class (`ClientExceptionInterface`) |

Async requests reject with the same exceptions.

```php
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;

try {
    $response = $client->get('/users');

    if ($response->clientError()) {
        echo "Client error: " . $response->status();
    } elseif ($response->serverError()) {
        echo "Server error: " . $response->status();
    }
} catch (NetworkException $e) {
    echo "Network problem: " . $e->getMessage();
} catch (HttpClientException $e) {
    echo "Request failed: " . $e->getMessage();
}
```

The original Guzzle exception is available via `$e->getPrevious()`. Its message, handler context (such as the
effective URL) and nested exceptions are redacted too, so the whole exception is safe to pass to a logger or an
error tracker; this also covers exceptions thrown by the Guzzle client from `getClient()`. The request object is
kept intact. Stack traces can still show your own call arguments (e.g. a URL passed to `get()`) unless
`zend.exception_ignore_args` is on, as it is in `php.ini-production`.

## Advanced Usage

### Middleware and Custom Handlers

```php
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

$client = $client->withMiddleware(
    Middleware::mapRequest(fn(RequestInterface $r) => $r->withHeader('X-Signature', sign($r))),
    'signer'
);
```

A custom `handler` in config is honored, which makes testing easy:

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

$client = new HttpClient('https://api.example.com', [
    'handler' => new MockHandler([new Response(200, [], '{"id": 1}')]),
]);
```

### Accessing Guzzle Client

```php
$guzzleClient = $client->getClient();
// Use Guzzle client directly for advanced features
```

### Custom Configuration

```php
$client = new HttpClient('https://api.example.com', [
    'timeout' => 30,
    'connect_timeout' => 10,
    'verify' => '/path/to/ca-bundle.pem', // custom CA bundle; never disable verification in production
    'proxy' => 'http://proxy.example.com:8080'
]);
```

## Development

```bash
# Run tests
composer test

# Run tests with HTML coverage report (requires Xdebug or PCOV)
composer test-coverage

# Run code style check
composer cs

# Fix code style
composer cs-fix

# Run static analysis
composer phpstan
```

## Requirements

- PHP 8.1 or higher
- Guzzle HTTP 7.10 or higher
- PSR-3 Logger (optional, for logging functionality)

## License

MIT License - see LICENSE file for details.
