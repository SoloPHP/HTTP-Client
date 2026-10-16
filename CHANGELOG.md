# Changelog

## 4.0.0

### Security

- **Arbitrary local file read / SSRF in multipart methods.** `postMultipart()`, `putMultipart()` and
  `patchMultipart()` treated any array with a `path` key as a file and opened it with `fopen()`. Forwarded form
  data like `avatar[path]=/etc/passwd`, `php://filter/...` or `http://internal/...` was read and sent to the remote
  server. Files are now attached only with `File::fromPath()` / `File::fromStream()`; arrays are sent as text fields.
- **Credential leak between callers.** `with*()` methods mutated the shared instance, so a token set for one user
  stayed on a DI singleton for everyone else. All configuration methods are now immutable.
- **Credentials sent to foreign hosts.** Tokens, basic, digest and NTLM auth are now sent only to the `base_uri`
  origin (string or `UriInterface`). They are added to the initial request only, so Guzzle drops them on
  cross-origin redirects even without `base_uri`.
- **Fail-open JSON.** `json()` returned the (truthy) PSR response object for invalid JSON, so
  `if ($response->json('allowed'))` passed on an HTML error page. It now returns `null`.
- **Unbounded `Retry-After`.** A server could hang the process forever (`Retry-After: 99999999`) or crash it with a
  `ValueError` (`Retry-After: -1`). Delays are now validated and capped (30 seconds by default).
- **CRLF injection in multipart part headers** via file name or field name is prevented: quotes, backslashes
  and control characters are percent-encoded like browsers do, in the `*Multipart()` helpers and in the raw
  `multipart` option of every request. Invalid MIME types and part header values are rejected.
- **Secrets in logs and exception messages.** Query values and URI credentials are redacted; secrets in the URL
  path can be registered with `withRedaction()`. The whole exception chain is redacted, including the original
  Guzzle exception in `getPrevious()` (message and handler context) and nested errors. Header values (fluent and
  constructor config) are validated up front, so an invalid token never ends up in an exception message.

### Fixed

- Retries were enabled by default and repeated POST/PATCH after timeouts and 5xx (duplicate side effects).
  Retries are now opt-in; non-idempotent methods are retried only on 429 or when the request was never sent.
- `json()` threw `TypeError` on scalar JSON bodies (`true`, `"ok"`, `42`).
- Integer and numeric-string keys in multipart data threw `TypeError`.
- A custom `handler` in config was silently replaced, so `MockHandler` and custom middleware did not work.
- PSR-3 loggers received every message at `info` level; failures are now logged at `error`.
- Async requests rejected with raw Guzzle exceptions while sync ones threw `RuntimeException`. Sync and async
  requests now share one path, so errors thrown before a request starts are wrapped the same way.
- Magic `__call()` reached private Guzzle methods and sent requests with bogus HTTP methods. It now always throws
  `BadMethodCallException`; `getConfig()` is available as a regular method.
- Exponential backoff was unbounded; the first retry waited 2× the configured delay.
- `body()` returned an empty string on the second call for non-seekable streams.
- Retries never resend a consumed non-seekable body (it would arrive empty).
- An array-valued `Authorization` header in config is accepted, as in Guzzle.
- `File::fromPath()` accepts local file names containing a colon (`report:2024.txt`).
- Each configured copy of the client reuses the same connection pool instead of creating a new handler, and
  builds its Guzzle client only on first use.

### Added

- `Psr\Http\Client\ClientInterface` is actually implemented; `sendRequest()` returns a PSR-7 response.
- PSR-18 exceptions: `HttpClientException`, `NetworkException`, `RequestException` (all extend `RuntimeException`).
- `File` value object, `withMiddleware()`, `withRedaction()`, `head()`, `send()`, `sendAsync()` and async
  variants for all methods.
- `retry()` parameters `$maxDelayMs` and `$retryNonIdempotent`.
- PHPUnit test suite (`composer test`, `composer test-coverage`).

## Upgrading from 3.x

1. **Use the returned instance** of `timeout()`, `retry()` and `with*()`:

   ```php
   // 3.x
   $client->withToken($token);
   $client->get('/me');

   // 4.0
   $client = $client->withToken($token);
   $client->get('/me');
   ```

2. **Attach files with `File`:**

   ```php
   // 3.x
   $client->postMultipart('/upload', ['avatar' => ['path' => $path, 'filename' => 'a.jpg']]);

   // 4.0
   $client->postMultipart('/upload', ['avatar' => File::fromPath($path, 'a.jpg')]);
   ```

   Old-style arrays no longer fail — they are sent as text fields `avatar[path]`, `avatar[filename]`.

3. **Enable retries explicitly** if you relied on the default: `->retry(3, 1000)`. To retry POST/PATCH on 5xx,
   pass `retryNonIdempotent: true` and use idempotency keys.

4. **Set `base_uri`** if the client calls the same host with credentials via absolute URLs, or pass
   `Authorization` per request for other hosts.

5. **`json()` returns `null` for invalid JSON** instead of the PSR response; use `toPsrResponse()` to get it.

6. **`sendRequest()` returns `ResponseInterface`** instead of `HttpResponse`.
