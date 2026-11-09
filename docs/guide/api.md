# Public API reference

This reference describes Solo HTTP Client 5.0: PHP 8.3+, `guzzlehttp/guzzle` 8.3+, `guzzlehttp/psr7` 3.2+, `guzzlehttp/promises` 3.1+, `psr/http-client` 1.0, `psr/log` 3.0 and the `Solo\HttpClient` namespace. [Getting started](index.md) walks through the setup, and [Behavior in detail](details.md) explains the rules below.

| Type | Role |
|---|---|
| `HttpClient` | Configures and sends requests; implements `Psr\Http\Client\ClientInterface` |
| `HttpResponse` | Wraps a PSR-7 response with status checks, body, JSON and header helpers |
| `File` | A file part for multipart requests (`final`) |
| `Exception\HttpClientException` | Base exception; PSR-18 `ClientExceptionInterface` |
| `Exception\NetworkException` | The network failed; PSR-18 `NetworkExceptionInterface` |
| `Exception\RequestException` | The request failed otherwise; PSR-18 `RequestExceptionInterface` |
| `Exception\BlockedHostException` | A `RequestException`: `withPublicHostsOnly()` refused a host that is not public |
| `Exception\RequestAwareException` | Abstract base of `NetworkException` and `RequestException`, with `getRequest()` |

Classes in `Solo\HttpClient\Internal` and `File::openStream()` are marked `@internal` and are not part of the public API.

Parameters that can carry secrets are marked `#[\SensitiveParameter]`: the base URI and the options of the constructor, URIs, request options, requests, headers, tokens, passwords, redaction secrets and multipart data. Stack traces show them as `SensitiveParameterValue` even when `zend.exception_ignore_args` is off.

## HttpClient

### Construction

```php
/** @param array<string, mixed> $config Guzzle options; a custom "handler" is honored */
public function __construct(string $baseUri = '', array $config = [])

/** @param array<string, mixed> $config */
public static function create(string $baseUri = '', array $config = []): self
```

`$config` takes [Guzzle client options](https://docs.guzzlephp.org/en/stable/request-options.html). The client sets or treats these itself:

| Option | Default | Behavior |
|---|---|---|
| `base_uri` | `$baseUri` | A string or a `UriInterface`; a `base_uri` in `$config` takes precedence over the argument. Its origin is where client credentials go |
| `timeout` | `30` | Seconds for the whole transfer |
| `http_errors` | `false` | 4xx and 5xx responses are returned, not thrown |
| `headers` | none | Must be an array; validated like `withHeaders()`. `Authorization` becomes the client's credential |
| `auth` | none | `[$user, $password]`, optionally with `'basic'`, `'digest'` or `'ntlm'`. Taken over by the client and bound to the origin. Basic credentials follow the rule of `withBasicAuth()`; digest and NTLM become cURL options |
| `handler` | Guzzle's default | A callable handler or a `HandlerStack`, created once and shared by every copy |

Any other option, such as `connect_timeout`, `verify`, `proxy`, `cookies` or `allow_redirects`, reaches Guzzle unchanged. The constructor builds the Guzzle client at once, so an invalid option fails there. A non-callable `handler`, non-array `headers` or an invalid header throws `InvalidArgumentException`.

### Configuration

```php
public function timeout(int|float $seconds): static

/** @param array<string, string|list<string>> $headers */
public function withHeaders(array $headers): static
public function withToken(string $token, string $type = 'Bearer'): static
public function withBasicAuth(string $username, string $password): static

public function retry(
    int $maxRetries = 3,
    int $delayMs = 1000,
    int $maxDelayMs = 30000,
    bool $retryNonIdempotent = false
): static

public function withLogging(LoggerInterface|callable|null $logger = null): static
public function withRedaction(string ...$secrets): static
public function withMiddleware(callable $middleware, string $name = ''): static

/** @param (callable(string): list<string>)|null $resolver */
public function withPublicHostsOnly(?callable $resolver = null): static
```

Each method returns a new client and leaves the current one unchanged.

| Method | Effect |
|---|---|
| `timeout()` | Sets the `timeout` option for every request |
| `withHeaders()` | Merges headers into the defaults, replacing a header with the same name in any letter case. A value is a string or a non-empty list of strings without control characters. `Authorization` becomes the client's credential and replaces digest or NTLM credentials |
| `withToken()` | `withHeaders(['Authorization' => "{$type} {$token}"])` |
| `withBasicAuth()` | `withToken(base64_encode("{$username}:{$password}"), 'Basic')`. As RFC 7617 and Guzzle 8 require, a colon in the username or a control character throws `InvalidArgumentException` |
| `retry()` | Replaces the retry policy. `$maxRetries = 0` turns retries off; a negative argument throws `InvalidArgumentException`. [Retries](details.md#retries) gives the rules |
| `withLogging()` | Replaces the logger. A PSR-3 logger receives `info` for responses and `error` for failed transfers; a callable receives the line as its only argument; `null` writes `[HttpClient] INFO: <line>` or `[HttpClient] ERROR: <line>` with `error_log()` |
| `withRedaction()` | Adds secrets to mask in log lines and exception messages, together with their URL-encoded forms. Empty strings are ignored |
| `withMiddleware()` | Adds a [Guzzle middleware](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html). Middleware run in the order added, inside the credential and redirect layers and outside retries and logging |
| `withPublicHostsOnly()` | Sends requests, redirect hops and retries only to public IP addresses, and pins each connection to the checked address with `CURLOPT_CONNECT_TO`; any other host throws `BlockedHostException` before anything is sent to it. `$resolver` receives a host name and returns its IP addresses as strings, an empty array when it does not resolve; by default they are the A and AAAA records from DNS. Throws `LogicException` without the cURL extension. [Public hosts only](details.md#public-hosts-only) gives the rules |

An invalid header name or value throws `InvalidArgumentException`. The message names the header, never the value.

### Requests

```php
/** @param array<string, mixed> $options Guzzle request options */
public function get(string $uri, array $options = []): HttpResponse
public function head(string $uri, array $options = []): HttpResponse
public function post(string $uri, array $options = []): HttpResponse
public function put(string $uri, array $options = []): HttpResponse
public function patch(string $uri, array $options = []): HttpResponse
public function delete(string $uri, array $options = []): HttpResponse

public function request(string $method, string $uri, array $options = []): HttpResponse
public function send(RequestInterface $request, array $options = []): HttpResponse
```

`$uri` is resolved against the base URI. `$options` apply to this request only:

- `headers` take precedence over client headers with the same name. They are checked before Guzzle sees them, by the rule of `withHeaders()`, which is Guzzle 8's: a value is a string or a non-empty list of strings. `'headers' => null` drops the client's headers for this request, but not its credentials.
- An `Authorization` header or an `auth` option takes precedence over the client's credentials and is sent to any host.
- `multipart` is prepared like the [multipart helpers](#multipart-helpers): `File` objects are accepted as contents, array contents become nested fields, names and file names are percent-encoded, and part headers are validated. Each part needs `name` and `contents`; `filename` must be a string and `headers` an array, and neither can be combined with array contents.

| Exception | Thrown when |
|---|---|
| `NetworkException` | No response: the host did not resolve, the connection or the TLS handshake failed, sending or receiving failed, or a timeout came before the response |
| `RequestException` | Another transfer failure, such as too many redirects or a response that broke off, or an error status when the request sets `http_errors` |
| `BlockedHostException` | After `withPublicHostsOnly()`, the host of the request or of a redirect target is not a public address and does not resolve to one; nothing is sent to it |
| `HttpClientException` | Any other Guzzle exception, such as an invalid request option |
| `InvalidArgumentException` | An invalid header or invalid multipart data, before anything is sent |

### Multipart helpers

```php
/** @param array<array-key, mixed> $data */
public function postMultipart(string $uri, array $data): HttpResponse
public function putMultipart(string $uri, array $data): HttpResponse
public function patchMultipart(string $uri, array $data): HttpResponse
```

Sends `$data` as `multipart/form-data`:

| Value | Sent as |
|---|---|
| `File` | A file part with the file's name and, when given, its MIME type |
| `StreamInterface` or a stream resource | A part with the stream's contents |
| A string, integer, float, boolean or `null` | A text field; `true` becomes `1`, `false` and `null` an empty value |
| `Stringable` | A text field with its string value |
| An array | Nested fields: `['tags' => ['a', 'b']]` becomes `tags[0]` and `tags[1]`, `['meta' => ['k' => 'v']]` becomes `meta[k]` |
| Anything else | `InvalidArgumentException` naming the field, before any file is opened |

The helpers take no options. To add headers or other options, pass the `multipart` option to `request()` or `post()`:

```php
$api->post('documents', [
    'headers' => ['X-Upload-Id' => $uploadId],
    'multipart' => [
        ['name' => 'scan', 'contents' => File::fromPath($path)],
        ['name' => 'meta', 'contents' => ['author' => 'Ada']], // meta[author]
    ],
]);
```

### Asynchronous requests

```php
/** @param array<string, mixed> $options */
public function getAsync(string $uri, array $options = []): PromiseInterface
public function headAsync(string $uri, array $options = []): PromiseInterface
public function postAsync(string $uri, array $options = []): PromiseInterface
public function putAsync(string $uri, array $options = []): PromiseInterface
public function patchAsync(string $uri, array $options = []): PromiseInterface
public function deleteAsync(string $uri, array $options = []): PromiseInterface

public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
```

The `GuzzleHttp\Promise\PromiseInterface` is fulfilled with an `HttpResponse` and rejected with the exceptions of the synchronous methods. An invalid header or invalid multipart data throws `InvalidArgumentException` at once instead of rejecting the promise.

### sendRequest()

```php
public function sendRequest(RequestInterface $request): ResponseInterface
```

The PSR-18 method. Sends the request without following redirects and returns the PSR-7 response for any status code. A relative URI is resolved against the base URI, and the client's headers, credentials, retries and logging apply. Transfer failures throw `NetworkException`, `RequestException` or `HttpClientException`.

### getClient() and getConfig()

```php
public function getClient(): GuzzleHttp\Client
public function getConfig(?string $option = null): mixed
```

`getClient()` returns the Guzzle client of this instance, built on first use and kept. Requests sent through it pass the same handler stack, so credentials, retries, logging and redaction apply, but the responses are plain PSR-7 responses and the exceptions are Guzzle's own, redacted.

`getConfig()` returns Guzzle's `Client::getConfig()`. The client's credentials do not appear in it: `Authorization` is absent from `headers`, and `auth` is absent once the client has taken it over.

### Other methods

Guzzle's magic request methods, such as `$client->options()`, are not forwarded: an undefined method throws `BadMethodCallException`. Use `request('OPTIONS', $uri)`.

## HttpResponse

```php
public function __construct(ResponseInterface $response)

public function status(): int
public function successful(): bool
public function ok(): bool
public function clientError(): bool
public function serverError(): bool

public function body(): string
public function json(?string $key = null): mixed
public function header(string $name): ?string
/** @return array<array<string>> */
public function headers(): array
public function toPsrResponse(): ResponseInterface
public function __toString(): string
```

| Method | Returns |
|---|---|
| `status()` | The status code |
| `successful()` | `true` for 200 to 299 |
| `ok()` | `true` for 200 only |
| `clientError()` | `true` for 400 to 499 |
| `serverError()` | `true` for 500 and above |
| `body()`, `__toString()` | The whole body. A seekable body is read again on each call; a non-seekable body is read once and kept |
| `json()` | The body decoded with associative arrays: an array, a scalar or `null`. Invalid JSON, including an empty body, gives `null`. The body is decoded once |
| `json($key)` | The value at a dot-separated path, such as `'data.items.0.id'`, or `null` when a segment is missing or the body is not an array. A key that contains a dot is reached through `json()['a.b']` |
| `header()` | The values of the header joined with `, `, matched in any letter case, or `null` when the header is absent |
| `headers()` | All headers, as PSR-7 `getHeaders()` returns them |
| `toPsrResponse()` | The wrapped PSR-7 response |

## File

```php
final class File

public static function fromPath(string $path, ?string $filename = null, ?string $mimeType = null): self
/** @param StreamInterface|resource $stream */
public static function fromStream($stream, string $filename, ?string $mimeType = null): self

public function filename(): string
public function mimeType(): ?string
```

`fromPath()` attaches a local file. The path is resolved with `realpath()` and must be a readable regular file; the resolved path is opened when the request is sent. The default file name is the base name of `$path` as given, so a symlink is sent under its own name. A colon inside a name is fine: `report:2024.txt` is a local file.

`fromStream()` attaches a PSR-7 stream or a stream resource, read when the request is sent.

| `InvalidArgumentException` | Cause |
|---|---|
| `File path must be a non-empty string without NUL bytes` | An empty path or a NUL byte |
| `Stream wrappers are not allowed in file path` | A path starting with `scheme://` or `data:`, such as `php://filter/...`, `http://...` or `phar://...` |
| `File does not exist or is not readable: <base name>` | A missing, unreadable or non-regular file; the message holds the base name only |
| `Stream must be a resource or Psr\Http\Message\StreamInterface` | Another value given to `fromStream()` |
| `File name must not be empty` | An empty `$filename` |
| `Invalid MIME type` | Anything other than `type/subtype` with optional parameters, such as `text/plain; charset=utf-8` |

Without a MIME type, Guzzle derives the part's `Content-Type` from the file name's extension and falls back to `application/octet-stream`.

## Exceptions

```text
RuntimeException
└── HttpClientException              Psr\Http\Client\ClientExceptionInterface
    └── RequestAwareException        abstract; getRequest()
        ├── NetworkException         Psr\Http\Client\NetworkExceptionInterface
        └── RequestException         Psr\Http\Client\RequestExceptionInterface
            └── BlockedHostException getHost(), getAddress()
```

| Exception | Wraps |
|---|---|
| `NetworkException` | Guzzle's `NetworkException`, for failures without a response: `ConnectException` (DNS, TCP connect, TLS setup), `ConnectTimeoutException`, `NetworkTimeoutException`, and send and receive errors |
| `RequestException` | Guzzle's `RequestException` and its `ResponseException` branch: `ResponseTransferException`, `ResponseTimeoutException`, `TooManyRedirectsException`, and `ClientException` or `ServerException` when a request sets `http_errors` |
| `HttpClientException` | Any other `GuzzleException`, such as `HandlerClosedException` or Guzzle's `InvalidArgumentException` for an invalid option, and a promise rejected with a value that is not an exception |

- `getMessage()` is `HTTP request failed: ` followed by Guzzle's message, redacted.
- `getCode()` is Guzzle's code.
- `getPrevious()` is the Guzzle exception, redacted, with its response when it is a `ResponseException`.
- `getRequest()` returns the request that failed, unchanged.

Exceptions that do not come from Guzzle, such as a `LogicException` thrown by your own middleware or resolver, reach the caller unchanged.

### BlockedHostException

```php
public function getHost(): string
public function getAddress(): string
```

A client restricted with `withPublicHostsOnly()` throws it, or rejects the promise with it, when the host of a request or of a redirect target is not a public address and does not resolve to one. Nothing is sent to that host, and the refusal is neither retried nor logged.

- `getHost()` is the host as the URI names it, such as `intranet.example.com`, `2130706433` or `[::1]`.
- `getAddress()` is the address that is not public: the host itself, or the first address it resolves to.
- `getRequest()` returns the refused request: the redirect target when a redirect was refused.
- `getMessage()` names both, with registered secrets masked, such as `HTTP request refused: intranet.example.com resolves to 10.0.0.5, which is not a public address`.
- `getCode()` is `0`, and there is no previous exception.

## Log line

```text
<method> <uri> HTTP/<version> <status or ->[ <Content-Length>][ <error>]
```

```text
GET https://api.example.com/v1/users?page=*** HTTP/1.1 200 1534
POST https://api.example.com/v1/payments HTTP/1.1 - cURL error 28: Operation timed out after 10002 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.example.com/v1/payments
```

The URI and the error message are redacted as described in [Redaction](details.md#redaction). Each attempt is a separate line.

## Errors and supported boundaries

- HTTP error statuses are returned as responses unless a request sets `http_errors`.
- Transfer failures throw the client's exceptions; invalid arguments throw `InvalidArgumentException` before any request is sent.
- A URL that Guzzle cannot parse throws Guzzle's `MalformedUriException`, whose message contains the URL as written.
- Stack traces hide the client's parameters that carry secrets. Arguments of your own functions, and of Guzzle's that it does not mark, stay visible unless `zend.exception_ignore_args` is on, as it is in `php.ini-production`.
- `getRequest()` returns the request with all its headers, `Authorization` included. Do not serialize it into logs.
- POST and PATCH are recognized as unsent through Guzzle's `ConnectException`. A custom handler must report connection failures with it for those retries to happen.
- Digest and NTLM credentials set on the client need the cURL extension. A request's own `auth` option follows Guzzle 8, which implements Digest itself and has no NTLM.
- `withPublicHostsOnly()` needs the cURL handler to pin connections. Guzzle's stream handler, which serves requests with the `stream` option, refuses restricted requests with an `HttpClientException`.
- `withPublicHostsOnly()` resolves host names with a blocking DNS lookup, bounded by the system resolver's timeouts rather than the `timeout` option. An asynchronous request resolves its host when it starts.
