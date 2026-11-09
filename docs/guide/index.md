# Getting started

Solo HTTP Client wraps Guzzle in an immutable, fluent client. Every
configuration method returns a new client, every request returns an
`HttpResponse` with status, JSON and header helpers, and the client implements
PSR-18, so code that depends on `Psr\Http\Client\ClientInterface` can use it as
well.

## Install

PHP 8.3+ is required. Composer installs Guzzle 8.3+ with its PSR-7 and promise
libraries, `psr/http-client` and `psr/log` with the package.

```sh
composer require solophp/http-client
```

## Create a client

```php
use Solo\HttpClient\HttpClient;

$api = HttpClient::create('https://api.example.com/v1/')
    ->timeout(10)
    ->withHeaders(['Accept' => 'application/json']);
```

`HttpClient::create()` and `new HttpClient()` take the base URI and, optionally,
an array of [Guzzle options](https://docs.guzzlephp.org/en/stable/request-options.html):

```php
$api = new HttpClient('https://api.example.com/v1/', [
    'connect_timeout' => 3,
    'proxy' => 'http://proxy.internal:8080',
    'verify' => '/etc/ssl/certs/internal-ca.pem', // never disable verification in production
]);
```

The client starts with a 30-second timeout and with `http_errors` off, so 4xx
and 5xx responses are returned rather than thrown.

Relative paths resolve against the base URI the way browsers resolve links.
Keep the trailing slash on the base URI and leave the leading slash off the path:

| Base URI | Path | Request |
| --- | --- | --- |
| `https://api.example.com/v1/` | `users` | `https://api.example.com/v1/users` |
| `https://api.example.com/v1/` | `/users` | `https://api.example.com/users` |
| `https://api.example.com/v1` | `users` | `https://api.example.com/users` |

## Make requests

```php
$response = $api->get('users', ['query' => ['page' => 2]]);
$response = $api->post('users', ['json' => ['name' => 'Ada']]);
$response = $api->put('users/42', ['json' => ['name' => 'Ada Lovelace']]);
$response = $api->patch('users/42', ['json' => ['email' => 'ada@example.com']]);
$response = $api->delete('users/42');
$response = $api->head('users/42');
$response = $api->request('OPTIONS', 'users');
```

The second argument takes Guzzle request options such as `query`, `json`,
`form_params`, `body`, `headers` and `timeout`. They apply to that request
only and never change the client.

## Read the response

```php
$response = $api->get('users/42');

$response->status();                // 200
$response->successful();            // true for any 2xx
$response->json();                  // ['id' => 42, 'profile' => ['email' => 'ada@example.com']]
$response->json('profile.email');   // 'ada@example.com'
$response->header('Content-Type');  // 'application/json', or null without the header
$response->body();                  // the raw body
$response->toPsrResponse();         // the PSR-7 response
```

Check error statuses with `successful()`, `clientError()` and `serverError()`.
`json()` returns `null` when the body is not valid JSON, for example an HTML
error page from a proxy, so a check such as `if ($response->json('allowed'))`
fails closed.

## Share one configured client

`timeout()`, `retry()` and every `with*()` method return a new client and leave
the original untouched. Configure one client, keep it in the container, and
derive variants per call:

```php
$shared = HttpClient::create('https://api.example.com/v1/')->retry(3, 500);

$shared->withToken($user->apiToken)->get('me'); // only this request carries the token
$shared->get('status');                         // no token here
```

Always use the returned instance: `$client->withToken($token);` on its own
changes nothing. The copies share one connection pool, and each builds its
Guzzle client only when it sends its first request.

A class that talks to one API can take the configured client in its constructor:

```php
final class BillingApi
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @return array<string, mixed>|null */
    public function invoice(int $id): ?array
    {
        $response = $this->http->get("invoices/{$id}");

        return $response->successful() ? $response->json() : null;
    }
}

$billing = new BillingApi(
    HttpClient::create('https://billing.example.com/api/')->withToken($billingToken)->retry(2, 250)
);
```

## Authenticate

```php
$api = $api->withToken($token);               // Authorization: Bearer <token>
$api = $api->withToken($token, 'Token');      // Authorization: Token <token>
$api = $api->withBasicAuth('user', 'secret'); // Authorization: Basic dXNlcjpzZWNyZXQ=
```

As RFC 7617 requires, a username cannot contain a colon, and neither part can
contain a control character.

Credentials set on the client go only to the origin of the base URI: the same
scheme, host and port. This covers these methods, an `Authorization` header in
`withHeaders()` or in the constructor options, and Guzzle's `auth` option,
including digest and NTLM. An absolute URL to another host, such as a
pagination link or a file location from a response, is requested without them,
and they never follow a redirect to another origin:

```php
$api = HttpClient::create('https://api.example.com/v1/')->withToken($token);

$api->get('users');                                  // with the token
$api->get('https://cdn.example.net/avatars/42.png'); // without it
```

Without a base URI, client credentials go to every host you request explicitly.
An `Authorization` header or `auth` option passed with one request is sent as
given, to any host. [Credentials and origins](details.md#credentials-and-origins)
lists the rules.

## Retry failed requests

Retries are off by default. `retry()` turns them on:

```php
$api = $api->retry(3, 1000);        // up to 3 retries, after about 1 s, 2 s and 4 s
$api = $api->retry(5, 500, 10_000); // each delay capped at 10 s (the default cap is 30 s)
$api = $api->retry(0);              // off again
```

GET, HEAD, OPTIONS, TRACE, PUT and DELETE are retried after a network error, a
429 and a 5xx response. POST and PATCH are retried after a 429, or when the
connection was never established: the host did not resolve, the connection was
refused or timed out, or the TLS handshake failed. A later timeout or a 5xx may
come after the server has already processed the request, and repeating it could
charge a card twice. If the API supports idempotency keys, allow those retries explicitly:

```php
$payments = $api->retry(3, 1000, retryNonIdempotent: true);

$payments->post('payments', [
    'headers' => ['Idempotency-Key' => $key],
    'json' => $payment,
]);
```

A `Retry-After` header replaces the computed delay, up to the cap.
[Retries](details.md#retries) has the full table and the delay formula.

## Upload files

```php
use Solo\HttpClient\File;

$api->postMultipart('users/42/documents', [
    'title' => 'Passport scan',
    'tags' => ['identity', 'scan'],                                // tags[0], tags[1]
    'scan' => File::fromPath('/srv/uploads/scan.jpg'),             // name and type from the path
    'report' => File::fromPath($path, 'report.pdf', 'application/pdf'),
    'export' => File::fromStream($stream, 'export.csv', 'text/csv'),
]);
```

`putMultipart()` and `patchMultipart()` work the same way. A file is attached
only through a `File` object. Arrays always become text fields, so request
data can be forwarded as it is: `avatar[path]=/etc/passwd` sent by a client
becomes the text field `avatar[path]`, never your server's file.

```php
$api->postMultipart('profile', $request->getParsedBody()); // text fields only
```

`File::fromPath()` accepts readable regular local files and rejects stream
wrappers such as `php://`, `http://` and `phar://`. Names and file names are
percent-encoded where needed, so `John's "CV".pdf` is sent as
`John's %22CV%22.pdf` and cannot inject part headers.

## Fetch URLs from users

A webhook, a feed import or an avatar download requests a URL that a user
typed, and that URL can point inside your network: `http://127.0.0.1:6379/`,
`http://10.0.0.5/admin`, or the cloud metadata service at
`http://169.254.169.254/` that hands out credentials. `withPublicHostsOnly()`
sends requests only to public IP addresses:

```php
use Solo\HttpClient\Exception\BlockedHostException;

$fetcher = HttpClient::create()->timeout(10)->withPublicHostsOnly();

try {
    $feed = $fetcher->get($feedUrl);
} catch (BlockedHostException $e) {
    $e->getHost();    // 'intranet.example.com'
    $e->getAddress(); // '10.0.0.5', the address that is not public
}
```

The client resolves the host itself, skips addresses that are not public and
connects to the first public one. A host without one is refused before
anything is sent, and so is a redirect to such a host: every redirect hop and
every retry is checked again. The connection is pinned to the checked
address, so a DNS server that answers with a public address for the check and
with a private one right after, which is called DNS rebinding, cannot send the
request elsewhere. A host that does not resolve fails with `NetworkException`,
as it does without the restriction.

Keep a separate client for your own services, which usually live at private
addresses. The restriction needs the cURL extension, and a request with the
`stream` option fails, because Guzzle's stream handler cannot pin a
connection. [Public hosts only](details.md#public-hosts-only) lists the
address ranges and the rules.

## Log requests

```php
$api = $api->withLogging($logger); // PSR-3: info for responses, error for failures
$api = $api->withLogging(function (string $line): void {
    file_put_contents('/var/log/app/http.log', $line . PHP_EOL, FILE_APPEND);
});
$api = $api->withLogging();        // error_log()
```

Each attempt becomes one line, with query values masked:

```text
GET https://api.example.com/v1/users?page=*** HTTP/1.1 200 1534
```

A secret inside the path cannot be recognized automatically. Register it with
`withRedaction()`, and it is masked in log lines and exception messages alike:

```php
$bot = HttpClient::create('https://api.telegram.org')
    ->withRedaction($botToken)
    ->withLogging($logger);

$bot->post("/bot{$botToken}/sendMessage", ['json' => $message]); // logged as /bot***/sendMessage
```

## Send requests concurrently

Every request method has an asynchronous variant that returns a Guzzle promise
of an `HttpResponse`:

```php
use GuzzleHttp\Promise\Utils;

$responses = Utils::unwrap([
    'user' => $api->getAsync('users/42'),
    'orders' => $api->getAsync('users/42/orders'),
]);

$name = $responses['user']->json('name');
```

The variants are `getAsync()`, `headAsync()`, `postAsync()`, `putAsync()`,
`patchAsync()`, `deleteAsync()`, `requestAsync()` and `sendAsync()`. A failed
transfer rejects the promise with the same exceptions the synchronous methods
throw.

## Handle errors

Error statuses are responses. Exceptions mean that the transfer itself failed:

| Problem | Exception |
| --- | --- |
| No response: the host did not resolve, the connection failed, sending or receiving failed, or a timeout came before the response | `NetworkException`, a PSR-18 `NetworkExceptionInterface` |
| Another transfer failure, such as too many redirects or a response that broke off | `RequestException`, a PSR-18 `RequestExceptionInterface` |
| After `withPublicHostsOnly()`, a host or a redirect target that is not a public address | `BlockedHostException`, a `RequestException`, before anything is sent to it |
| Any other Guzzle error | `HttpClientException`, a PSR-18 `ClientExceptionInterface` |
| An invalid header, unsupported multipart data, a missing file | `InvalidArgumentException`, before anything is sent |

The first four live in `Solo\HttpClient\Exception`. `NetworkException` and
`RequestException` extend `HttpClientException`, which extends
`RuntimeException`.

```php
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;

try {
    $response = $api->get('users/42');
} catch (NetworkException $e) {
    // no response: the API is unreachable
    throw new ServiceUnavailable('The users API is unreachable', previous: $e);
} catch (HttpClientException $e) {
    $logger->error('The users API request failed', ['exception' => $e]);
    throw $e;
}

if ($response->status() === 404) {
    return null;
}
```

The message and the chain of previous exceptions are
redacted like log lines, and parameters that carry secrets, such as the URI and
the options, are hidden in stack traces. An exception can go to a logger or an
error tracker as it is. [Redaction](details.md#redaction) explains what is
masked.

## Test code that uses the client

Pass a Guzzle `MockHandler` as the handler, and no request leaves the process:

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

$api = new HttpClient('https://api.example.com/v1/', [
    'handler' => new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"id": 42}'),
    ]),
]);

$api->get('users/42')->json('id'); // 42
```

The client keeps its own layers around a custom handler: credentials, retries,
logging, redaction and the public-host check work as they do in production. To inspect what was sent,
add Guzzle's history middleware:

```php
use GuzzleHttp\Middleware;

$history = [];
$api = $api->withMiddleware(Middleware::history($history));

$api->get('users/42');
$history[0]['request']->getHeaderLine('Authorization');
```

A client restricted with `withPublicHostsOnly()` still resolves host names
through DNS. Pass a resolver that answers from a table instead, so the test
stays offline:

```php
$fetcher = $api->withPublicHostsOnly(static fn(string $host): array => match ($host) {
    'feeds.example.com' => ['93.184.216.34'],
    'intranet.example.com' => ['10.0.0.5'],
    default => [], // does not resolve
});
```

## Further reading

- [Public API reference](api.md)
- [Behavior in detail](details.md)
- [Upgrading from 4.x](https://github.com/SoloPHP/HTTP-Client/blob/main/CHANGELOG.md#upgrading-from-4x)
