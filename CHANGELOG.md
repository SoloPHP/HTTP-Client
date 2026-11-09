# Changelog

## 5.0.0-RC4

### Fixed

- **A body that failed to read is no longer taken for the body.** `HttpResponse::json()` does not cache `null` after
  a failed read, and for a non-seekable body `body()` and `json()` throw the same exception again instead of
  returning what was left after the failure.
- **Streamed responses under `withMaxResponseSize()`** ask the body for at most one byte past the limit instead of
  allocating the buffer the caller requested. The body is counted by its position, so seeking back and reading it
  again within the limit is allowed.
- **Registered secrets in host names stay out of stack traces**, also with `zend.exception_ignore_args` off: in a
  refusal of `withPublicHostsOnly()`, and when its resolver answers with something other than IP addresses.

## 5.0.0-RC3

### Added

- **`withMaxResponseSize()`** refuses a response whose body is larger than the limit with the new
  `ResponseTooLargeException`, a `RequestException` with `getLimit()`: by its `Content-Length` as soon as the headers
  arrive, otherwise once the body goes past the limit. The body is counted as it is written to the sink, after
  decoding, so a small compressed response cannot expand past the limit, and a sink never receives the bytes past
  it; a streamed body is counted as it is read. The refusal is not retried. Guzzle 8 refuses
  `CURLOPT_MAXFILESIZE_LARGE` in the `curl` option, so this is the way to cap a download.
- **Timeouts have their own classes.** `NetworkTimeoutException`, a `NetworkException`, for a timeout before the
  response, and `ResponseTimeoutException`, a `RequestException`, for one after its headers, share the
  `TimeoutException` interface. Guzzle 8 exceptions have no handler context to read the cURL error from.

### Changed

- **Retries skip failures that would only repeat.** A transfer that a callback, the sink or `withMaxResponseSize()`
  stopped, and a request that Guzzle refuses, are no longer retried. Network errors and responses that broke off
  after their headers are retried as before.
- **A URL that does not parse** throws `HttpClientException` with Guzzle's `MalformedUriException` in
  `getPrevious()`, both redacted, instead of the bare `MalformedUriException`: URLs that users supply fail like any
  other request.
- **One DNS query per host in the usual case.** `withPublicHostsOnly()` asks for the AAAA records of a host only when
  none of its A records is public, and only for the family that `force_ip_resolve` names.

## 5.0.0-RC2

### Added

- **`withPublicHostsOnly()` against server-side request forgery (SSRF).** URLs that users supply, such as webhooks,
  feed imports and image downloads, can be kept to public IP addresses. A host that is not a public address and does
  not resolve to one is refused before anything is sent, with the new `BlockedHostException`, a `RequestException`
  that names the host and the address. Refused are loopback, private networks, link-local addresses including the
  cloud metadata service at 169.254.169.254, carrier-grade NAT (100.64.0.0/10), the other IPv4 special-purpose
  ranges, every IPv6 address outside global unicast, IPv4-mapped and NAT64 forms of refused IPv4 addresses, and
  numeric spellings such as `2130706433`. Every redirect hop and every retry is checked again, and the connection
  is pinned to the checked address with `CURLOPT_CONNECT_TO`, so DNS rebinding cannot swap in another address.
  Host names resolve to their A and AAAA records from DNS, or through a resolver you pass. Requires the cURL
  extension.

## 5.0.0-RC1

Built on Guzzle 8. Request options, middleware, handlers, promises, `getClient()` and the Guzzle exceptions in
`getPrevious()` follow Guzzle 8; see [Upgrading from 4.x](#upgrading-from-4x).

### Changed

- **PHP 8.3 or later** is required.
- **Guzzle 8.3** with `guzzlehttp/psr7` 3.2 and `guzzlehttp/promises` 3.1 replaces Guzzle 7.
- **Exceptions follow Guzzle 8's classification.** `NetworkException` now covers every failure without a response,
  including send and receive errors that were `RequestException`. `RequestException` covers request failures and
  responses that broke off: a timeout after the response headers is now `RequestException`, with the response in
  `getPrevious()`.
- **Retries.** "Not sent" means Guzzle's `ConnectException`, so POST and PATCH are also retried after a connect
  timeout, with any handler rather than only on cURL errors 6, 7 and 35. A response that breaks off after its
  headers is retried for GET, HEAD, OPTIONS, TRACE, PUT and DELETE.
- **Per-request header values** must be strings or non-empty lists of strings, as Guzzle 8 requires.
- **Basic credentials** in `withBasicAuth()` and the `auth` option follow RFC 7617, as in Guzzle 8: a colon in the
  username or a control character is rejected.
- **A request's own `auth` option** takes precedence over the client's credentials. Guzzle 8 applies it inside the
  handler stack, after the client adds its credentials.

### Security

- **Secrets stay out of stack traces.** Parameters that can carry secrets are marked `#[\SensitiveParameter]`: URIs,
  request options, headers, tokens, passwords, redaction secrets and multipart data. Stack traces show them as
  `SensitiveParameterValue` even when `zend.exception_ignore_args` is off.

### Removed

- Redaction of the handler context: Guzzle 8 exceptions have none.

## Upgrading from 4.x

1. **Require PHP 8.3 and allow Guzzle 8.** Packages that pin `guzzlehttp/guzzle` ^7, `guzzlehttp/psr7` ^2 or
   `guzzlehttp/promises` ^2 must allow the new majors first.

2. **Read Guzzle's [7.0 to 8.0 upgrade guide](https://github.com/guzzle/guzzle/blob/8.3.1/UPGRADING.md)** for the
   request options, middleware and handlers you pass to the client.

3. **Review catch blocks.** Send and receive errors are `NetworkException` now, and a timeout after the response
   headers is `RequestException`. Catch `HttpClientException` to handle every transfer failure:

   ```php
   try {
       $response = $client->get('/users');
   } catch (NetworkException $e) {
       // no response: DNS, connection, send or receive error, timeout before the response
   } catch (HttpClientException $e) {
       // any other transfer failure
   }
   ```

4. **Guzzle exceptions in `getPrevious()`** have no `getHandlerContext()`. `getResponse()` exists only on Guzzle's
   `ResponseException` and its subclasses. Catch `TimeoutException` to tell a timeout apart, and read anything else
   about the transfer through Guzzle's `on_stats` option.

5. **Cap downloads with `withMaxResponseSize()`.** Guzzle 8 refuses `CURLOPT_MAXFILESIZE_LARGE`, like other raw cURL
   options outside its allow-list, in the `curl` option, and every request that passes it fails.

6. **Per-request header values must be strings:**

   ```php
   // 4.x
   $client->get('/items', ['headers' => ['X-Page' => 2]]);

   // 5.0
   $client->get('/items', ['headers' => ['X-Page' => '2']]);
   ```

7. **Basic auth usernames cannot contain a colon.** The server would split such a username at the colon.

## 4.0.1

### Security

- **Secrets in per-request header errors.** An invalid per-request header value, such as a token read from a file
  with its trailing newline, made Guzzle throw an exception whose message contained the value. Per-request headers
  are now checked first, and the message names only the header.
- **Guzzle 7.15.2 is the minimum.** Earlier Guzzle and `guzzlehttp/psr7` releases have known vulnerabilities,
  including host confusion (CVE-2026-69246, and CVE-2026-59882 in `guzzlehttp/psr7`) that could defeat the origin
  check client credentials rely on.

### Fixed

- `File::fromPath()` sent a symlink under the name of its target, so the part also got the MIME type guessed from
  that name. The default file name comes from the path as given again, as in 3.x.

### Added

- [Documentation](https://solophp.github.io/HTTP-Client/): getting started, API reference and behavior in detail.

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
