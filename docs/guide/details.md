# Behavior in detail

Solo HTTP Client adds safe defaults around Guzzle and leaves the transfer to it. [Getting started](index.md) shows the setup, and the [public API](api.md) describes the contracts. This page explains the rules and how the package is tested.

## Design decisions

- **Configuration is immutable.** A client usually lives in a container and serves many callers. If `withToken()` changed the shared instance, one user's token would stay there for the next caller. Every configuration method returns a copy instead, and the copies share one handler, so they reuse connections.
- **Credentials belong to an origin.** API responses contain absolute URLs: pagination links, file locations, callbacks. Following one must not hand the token to another host, so client credentials are bound to the origin of the base URI.
- **Retries are opt-in and conservative.** Repeating a request is safe when the request is idempotent or never reached the server. POST and PATCH are repeated only in those cases, unless the API uses idempotency keys and you say so.
- **Files are explicit.** In 3.x an array with a `path` key was read as a file, so forwarded form data could make the server upload its own files. Now only `File` objects are files.
- **A download has a ceiling.** A URL from a user can serve gigabytes, or a small gzip body that expands to them. `withMaxResponseSize()` counts what reaches the sink after decoding, not what the server announces or what arrives on the wire.
- **The address is checked where the connection is made.** A URL from a user can lead into the local network, and checking it up front is not enough: a redirect, a retry or a second DNS answer can lead somewhere else. `withPublicHostsOnly()` checks every attempt right above the handler and pins the connection to the address it checked.
- **Status codes are data.** `http_errors` is off: a 404 or a 503 is a response to inspect, and an exception means the transfer itself failed, as PSR-18 expects.
- **Secrets stay out of text.** Log lines, exception messages and stack traces reach people and tools that should not see tokens. Query values, URI credentials and registered secrets are masked wherever the client writes or rethrows text, and parameters that carry secrets are hidden in traces.
- **JSON fails closed.** Invalid JSON decodes to `null`, never to a truthy value.

## Structure

```text
src/
├── HttpClient.php                  Configuration, requests and the Guzzle handler stack
├── HttpResponse.php                Status checks, body, JSON and headers
├── File.php                        A file part for multipart requests
├── Exception/
│   ├── HttpClientException.php     Base exception, PSR-18 ClientExceptionInterface
│   ├── RequestAwareException.php   Base of the exceptions that carry the request
│   ├── NetworkException.php        PSR-18 NetworkExceptionInterface
│   ├── RequestException.php        PSR-18 RequestExceptionInterface
│   ├── TimeoutException.php        Interface of the two timeouts
│   ├── NetworkTimeoutException.php A timeout before the response
│   ├── ResponseTimeoutException.php A timeout after the response headers
│   ├── BlockedHostException.php    A host refused by withPublicHostsOnly()
│   └── ResponseTooLargeException.php A response refused by withMaxResponseSize()
└── Internal/
    ├── HeaderParam.php             Percent-encoding of multipart names and file names
    ├── IpAddress.php               IP literals in hosts, and which addresses are public
    ├── PublicHostGuard.php         The public-host check, DNS lookup and connection pinning
    ├── ResponseSizeLimit.php       The response size limit: Content-Length and the counted sink
    ├── SizeLimitedStream.php       A sink or streamed body that counts its bytes
    ├── Redactor.php                Masking of URLs, secrets and exception chains
    └── RedactingFormatter.php      The log line
```

## The handler stack

Each copy of the client builds its Guzzle client when it sends its first request; the constructor builds one at once, so invalid options fail early. The handler stack, from the outermost layer inwards:

| Layer | Name | Role |
|---|---|---|
| Redaction | `solo_redact` | Rebuilds failed transfer exceptions with redacted messages and previous chain |
| Credentials | `solo_auth` | Adds client credentials to requests for the origin |
| Guzzle defaults | `http_errors`, `allow_redirects`, `auth`, `cookies`, `prepare_body` | As `HandlerStack::create()` sets them up; `auth` applies a request's own `auth` option |
| Your middleware | the names given to `withMiddleware()` | In the order added |
| Retries | `solo_retry` | Present after `retry()` |
| Logging | `solo_log` | Present after `withLogging()` |
| Response size | `solo_max_response_size` | Present after `withMaxResponseSize()`: refuses a response by its `Content-Length` and counts the body as it reaches the sink |
| Public hosts | `solo_public_hosts` | Present after `withPublicHostsOnly()`: checks the host of each attempt and pins its connection |
| Handler | | Guzzle's default handler, or the `handler` option |

The order has these consequences:

- Credentials are added once, to the initial request. Guzzle's redirect middleware runs inside that layer, strips them on a hop to another origin, and nothing adds them back.
- Your middleware sees each redirect hop once, with credentials, and does not see retries.
- Every attempt is logged: a request retried twice produces three lines.
- The public-host check sees every attempt of every redirect hop as it goes to the handler, after your middleware has changed the request. A refused attempt is thrown before the retry and logging layers wait on it, so it is never retried and leaves no log line.
- The response size limit applies to every attempt of every redirect hop on its own. A refused response reaches the retry layer as `ResponseTooLargeException`, which it does not retry, and is logged.
- When a request sets `http_errors`, retries still see the response, and the status turns into an exception after the last attempt.

When the `handler` option is a `HandlerStack`, each build works on a copy and your stack is never changed. A plain callable is wrapped with `HandlerStack::create()`. The handler itself, Guzzle's default or yours, is created once and shared by every copy of the client.

## Credentials and origins

Client credentials come from:

- `withToken()`, `withBasicAuth()` or an `Authorization` header in `withHeaders()`;
- an `Authorization` header in the constructor's `headers` option;
- the constructor's `auth` option. Basic credentials become an `Authorization` header; digest and NTLM credentials become the cURL options `CURLOPT_HTTPAUTH` and `CURLOPT_USERPWD`.

Basic credentials follow RFC 7617: the username cannot contain a colon, which would split it, and neither part can contain a control character. Guzzle 8 applies the same rule to its `auth` option.

The client holds one credential at a time. A new `Authorization` header replaces digest or NTLM credentials, and in the constructor the `auth` option replaces an `Authorization` header.

The credential is added to a request when:

- the client has no base URI, or
- the request's scheme, host and port equal those of the base URI. Letter case and default ports do not matter, so `https://API.example.com:443/` is the origin of `https://api.example.com/v1/`. A subdomain, `http` instead of `https` or another port is a different origin,

and the request does not bring its own credentials: an `Authorization` header or an `auth` option passed with the request is sent as given, to any host, and the client adds nothing.

Redirects never carry client credentials to another origin, with or without a base URI. They are added before Guzzle follows redirects, Guzzle removes the `Authorization` header and cURL credentials on a cross-origin hop, and a later hop back to the origin does not get them again. Guzzle 8 also drops a request's own `auth` option on a cross-origin hop.

## Public hosts only

After `withPublicHostsOnly()`, every attempt of every redirect hop passes this check on its way to the handler:

1. A host that is an IP address is taken as it is. Besides dotted quads and IPv6 addresses in brackets, this covers the other numeric spellings that cURL reads as IPv4 addresses: `2130706433`, `0x7f000001`, `0177.0.0.1` and `127.1` all mean `127.0.0.1`. An IPv4-mapped IPv6 address such as `[::ffff:127.0.0.1]` is the IPv4 address it carries.
2. Any other host is resolved, by default to its A records from DNS, and to its AAAA records only when no A record is public, since the first public address is the one used. With the `force_ip_resolve` option set to `v4` or `v6`, only addresses of that family are asked for and count.
3. Addresses that are not public are skipped, and the connection is pinned to the first public one. Without a public address the request is refused with `BlockedHostException`. A host without any address fails with Guzzle's `ConnectException`, which the client reports as `NetworkException`, the same as a lookup that fails in cURL.

An address is public when it is globally reachable unicast, as the IANA special-purpose address registries define it. These ranges are refused:

| Range | What it is |
|---|---|
| `0.0.0.0/8` | "This network": `0.0.0.0` reaches the local host |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | Private networks |
| `100.64.0.0/10` | Shared address space of carrier-grade NAT |
| `127.0.0.0/8` | Loopback |
| `169.254.0.0/16` | Link-local, including the cloud metadata service at `169.254.169.254` |
| `192.0.0.0/24` | IETF protocol assignments |
| `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` | Documentation |
| `192.88.99.0/24` | Former 6to4 relay anycast |
| `198.18.0.0/15` | Benchmarking |
| `224.0.0.0/4` | Multicast |
| `240.0.0.0/4` | Reserved, including the broadcast address `255.255.255.255` |
| IPv6 outside `2000::/3` | Everything but global unicast: loopback `::1`, `::`, unique local `fc00::/7` with the AWS metadata service at `fd00:ec2::254`, link-local `fe80::/10`, multicast `ff00::/8` and the rest |
| `2001::/23` | IETF protocol assignments, including Teredo |
| `2001:db8::/32`, `3fff::/20` | Documentation |
| `2002::/16` | 6to4, which reaches the IPv4 address it embeds |
| `64:ff9b::/96` with a refused IPv4 address | NAT64 into a private network. NAT64 to a public IPv4 address is allowed, so IPv6-only networks with DNS64 keep working |

PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` is not used: it lets carrier-grade NAT, benchmarking and documentation addresses through, and its IPv6 ranges differ between PHP versions.

**Pinning.** The connection is pinned with `CURLOPT_CONNECT_TO` and an empty host and port, such as `::93.184.216.34:`, so cURL connects to the checked address whatever host the request names and never resolves the host again. A DNS server that answers with a public address for the check and with a private one right after, which is called DNS rebinding, cannot send the request elsewhere. The request itself is unchanged: certificate verification, SNI and the `Host` header use the host from the URI. cURL does not reuse a pooled connection to another address for a pinned request. `CURLOPT_RESOLVE`, the usual alternative, gives neither guarantee: cURL reuses a pooled connection to the same host and port whatever address it reached, and the entry stays in the DNS cache that later requests share. A `CURLOPT_CONNECT_TO` of your own in the `curl` option is replaced.

**Redirects and retries.** Each hop and each retry is checked and pinned on its own. A redirect to `http://169.254.169.254/` is refused, and so is a later lookup of the same host that answers with a private address. A refused request is not retried and leaves no log line. A failed lookup is retried like a connection that could not be established, POST and PATCH included, because nothing was sent.

**Proxies.** With the `proxy` option or a proxy from the environment, cURL asks the proxy to tunnel to the checked address with CONNECT, `http` URLs included, so the proxy cannot resolve the host differently. The proxy has to allow CONNECT to the port of the URL. The address of the proxy itself is not checked.

**Limits.**

- The default resolver asks DNS directly and does not read the hosts file: a name defined only there does not resolve. The lookup blocks, and its timeouts are the system resolver's, not the `timeout` option. An asynchronous request resolves its host when it starts.
- A resolver you pass receives a host name and returns its IP addresses as strings, or an empty array when the host does not resolve. Anything else throws `UnexpectedValueException`.
- Pinning needs cURL: `withPublicHostsOnly()` throws `LogicException` without the cURL extension, and Guzzle's stream handler, which serves requests with the `stream` option, refuses restricted requests.
- An IPv6 literal that is not a plain address, such as an IPvFuture literal, cannot be checked and fails as a host that does not resolve.

## Response size

After `withMaxResponseSize()`, each attempt of each redirect hop counts its own response:

1. A `Content-Length` larger than the limit refuses the response as soon as its headers arrive, before the body is read. It is ignored for a HEAD request and for 1xx, 204 and 304 responses, which carry no body. A `Content-Length` of any number of digits is compared exactly.
2. The body is counted as the handler writes it to the `sink` option, after decoding: with `decode_content`, Guzzle's default, a gzip body counts as the bytes it expands to, not the bytes received. A write that would go past the limit refuses the response, and the sink never receives it, so a file sink holds at most the limit.
3. With the `stream` option the body is not written to a sink. It is counted as you read it, and the read that goes past the limit throws.

The sink option keeps its meaning: a path is opened as Guzzle opens it, a resource stays yours to close, a stream receives the body. Without one, the body goes to a `php://temp` stream as it does in Guzzle. A refused response throws `ResponseTooLargeException`, is not retried and, after `withLogging()`, is logged as a failure.

## Retries

| Outcome of an attempt | GET, HEAD, OPTIONS, TRACE, PUT, DELETE | POST, PATCH and other methods | With `retryNonIdempotent` |
|---|---|---|---|
| 429 response | Retried | Retried | Retried |
| 5xx response | Retried | Not retried | Retried |
| Not sent: the host did not resolve, the connection was refused or timed out, the TLS handshake failed | Retried | Retried | Retried |
| Any other network or transfer error, including a timeout after sending and a response that broke off after its headers | Retried | Not retried | Retried |
| A transfer that a callback, the sink or `withMaxResponseSize()` stopped, a refused host, a request that Guzzle refuses | Not retried | Not retried | Not retried |
| Any other response | Not retried | Not retried | Not retried |

- Methods are compared in any letter case.
- "Not sent" means Guzzle's `ConnectException`, `ConnectTimeoutException` included: Guzzle 8 throws it only while the connection is being established, before any byte of the request is sent, from its cURL and stream handlers alike.
- A response that breaks off after its headers, Guzzle's `ResponseTransferException`, is judged as a network error, not by its status: the body never arrived.
- A transfer that an `on_headers` or `progress` callback, a failing sink or the response size limit stopped would only stop again, and so would a request that Guzzle refuses, such as one with an unsupported scheme: none is retried.
- A request whose body is not seekable, such as a stream that can be read only once, is never retried: the retry would send an empty body.
- `$maxRetries` counts retries, so `retry(3)` allows four attempts.

Before retry *n*, the client waits `min($maxDelayMs, $delayMs × 2ⁿ⁻¹)` milliseconds plus a random jitter of up to 10% of that value, and never more than `$maxDelayMs`:

| Policy | Retry 1 | Retry 2 | Retry 3 | Retry 4 | Retry 5 |
|---|---|---|---|---|---|
| `retry(5, 1000)` | 1000–1100 ms | 2000–2200 ms | 4000–4400 ms | 8000–8800 ms | 16000–17600 ms |
| `retry(5, 100, 300)` | 100–110 ms | 200–220 ms | 300 ms | 300 ms | 300 ms |

When the response that triggers a retry has a valid `Retry-After` header, its value replaces the computed delay, capped at `$maxDelayMs` and without jitter. Two forms are valid: a number of seconds, up to ten digits, and an IMF-fixdate such as `Wed, 21 Oct 2015 07:28:00 GMT`, where a date in the past means no wait. Any other value, such as `-1`, `1.5` or an obsolete date format, is ignored, and the computed delay applies. The wait itself uses Guzzle's `delay` option.

## Multipart

The multipart helpers turn a field map into parts: nested arrays become `name[key]` fields, `File` objects become file parts, and every other supported value becomes a text field. The `multipart` option of any request goes through the same preparation, so the protections below apply to both, on synchronous and asynchronous paths:

- **Files are explicit.** Only a `File` is opened as a file. An array is a set of text fields, whatever its keys.
- **No stream wrappers.** `File::fromPath()` rejects `scheme://` and `data:` paths and stores the path returned by `realpath()`, so `fopen()` never reaches a wrapper.
- **No header injection.** In names and file names, double quotes, backslashes and control characters, CR and LF included, are percent-encoded the way browsers encode them, so a value can neither close the quoted parameter nor start a new header line. MIME types and part headers with invalid characters are rejected.
- **Validation first.** Every part is validated before the first `File` is opened, so a rejected call opens no file and leaves the streams you passed open and intact.

## Redaction

| Where | What is masked |
|---|---|
| The URI in a log line | Query values become `***` (`?page=***&q=***`, and a bare value such as `?SECRET` becomes `?***`); user info and the fragment are removed; then registered secrets |
| Exception messages and log errors | Every absolute URL in the text, treated like the URI above, not only the request URI: redirect targets and effective URLs appear in Guzzle messages too; then registered secrets |
| Previous exceptions | The whole chain. A Guzzle transfer exception keeps its class, request and response; a non-Guzzle exception with something to mask becomes a `RuntimeException` whose message starts with its original class name |
| Stack traces | The client's parameters that can carry secrets are `#[\SensitiveParameter]`, so PHP records them as `SensitiveParameterValue` even when `zend.exception_ignore_args` is off |

- A registered secret is matched as given and in its `rawurlencode()` and `urlencode()` forms. The longest match wins in a single pass, so overlapping secrets are masked completely.
- An exception with nothing to mask is kept as it was, with its stack trace.
- Exceptions from the Guzzle client returned by `getClient()` pass the same redaction layer.
- Header values set on the client or passed with a request are checked before Guzzle builds the request, so an invalid value, often a token with a stray newline, never reaches an exception message.
- The request returned by `getRequest()` is not changed: it keeps its URI and its headers.

## PSR-18

- `HttpClient` implements `Psr\Http\Client\ClientInterface`. `sendRequest()` never throws for a status code, does not follow redirects, and returns the PSR-7 response.
- `NetworkException` implements `NetworkExceptionInterface` and `RequestException` implements `RequestExceptionInterface`; both return the failed request from `getRequest()`. Both extend `HttpClientException`, which implements `ClientExceptionInterface`.
- Type against `ClientInterface` where code only sends PSR-7 requests, and against `HttpClient` where it uses the fluent API.

## Testing

`composer check` validates `composer.json` and runs PHPUnit, PHPStan at its maximum level on `src/`, and the PSR-12 check on `src/` and `tests/`. The suite replaces the network with Guzzle's `MockHandler` behind a recording handler, so every attempt, its options and its body are checked without a server, and retries run without sleeping. Public-host tests resolve names from a table, so they make no DNS lookups either. `.github/workflows/tests.yml` runs the check on PHP 8.3, 8.4 and 8.5, and on PHP 8.3 with the lowest version of every dependency. `.github/workflows/docs.yml` builds this site.

## Verified behavior

The test suite checks, among other things:

- that every configuration method returns a new client and leaves the original and its Guzzle client untouched, that one caller's token never reaches a shared instance, that headers merge in any letter case, and that copies build their Guzzle client on first use and share the default handler;
- credentials sent only to the base origin, with a string or a `UriInterface` base URI, for tokens, basic auth, `Authorization` headers in the constructor, array values, digest and NTLM;
- credentials dropped on cross-origin redirects with and without a base URI and kept on same-origin redirects, per-request `Authorization` headers kept as given, and a request's own `auth` option taking precedence;
- invalid header names and values rejected without the value in the message, set on the client, in the constructor or with a request, and basic credentials checked by RFC 7617;
- secrets kept out of stack traces with `zend.exception_ignore_args` off, for rejected tokens, passwords and headers and for failed transfers;
- retries off by default; POST not retried after a 5xx, a timeout after sending or a broken response, retried after a 429, a connect failure or a connect timeout, and after a 5xx when allowed; GET retried after a broken response and not after a callback abort or a request that Guzzle refuses; idempotent methods retried after a 5xx and network errors; the retry limit; exponential, capped backoff; `Retry-After` in seconds and as a date, capped and ignored when invalid; non-seekable bodies sent once and seekable bodies retried intact; negative parameters rejected;
- arrays with a `path` key sent as text; stream wrappers, missing files, directories, NUL bytes and empty names rejected; files from paths, streams and symlinks, with the link's own name; quotes, backslashes and CR/LF encoded in names and file names; invalid MIME types and part headers rejected; caller streams untouched after a failed build; integer keys and nested arrays; the raw `multipart` option on synchronous and asynchronous paths;
- `null` from `json()` for HTML, empty and truncated bodies, scalar JSON values, nested keys, seekable bodies read again and non-seekable bodies cached;
- masked query values and user info in log lines, PSR-3 levels, the default `error_log()` writer, path secrets and their URL-encoded forms in logs and exceptions;
- redacted previous chains on synchronous and asynchronous paths, every Guzzle transfer branch keeping its class, request, code and response, timeouts caught as `TimeoutException`, a URL that does not parse failing as `HttpClientException` with secrets masked, untouched exceptions with nothing to hide, and the Guzzle client from `getClient()`;
- after `withPublicHostsOnly()`: loopback, private, link-local, carrier-grade NAT, benchmarking, documentation, multicast and reserved IPv4 and IPv6 addresses refused before sending, written as dotted quads, in brackets, IPv4-mapped, as NAT64 or 6to4 addresses, and in numeric spellings such as `2130706433`, `0177.0.0.1` and `127.1`; public addresses and hosts that resolve to them pinned with `CURLOPT_CONNECT_TO`, with the URI and `Host` header unchanged; addresses that are not public skipped; `force_ip_resolve` honored; redirects to private addresses and to names that resolve to them refused, each hop pinned to its own address; DNS rebinding refused between redirect hops and between retries; failed lookups reported as `NetworkException` and retried; refusals neither retried nor logged, rejected on the asynchronous path, PSR-18 request exceptions with registered secrets masked; the Guzzle client from `getClient()` restricted as well; a `CURLOPT_CONNECT_TO` of the `curl` option replaced; and the stream handler refusing restricted requests;
- after `withMaxResponseSize()`: bodies up to the limit returned; a larger `Content-Length`, one past the integer range, and a body past the limit without one refused; `Content-Length` of HEAD and 304 responses ignored; refusals not retried and rejected on the asynchronous path; a path sink filled with the body and never past the limit, a resource sink left open, an `on_headers` callback of your own still called, and a streamed body counted as it is read;
- the PSR-18 interface, responses for error statuses, network and request exceptions, asynchronous errors and responses wrapped like synchronous ones, rejected magic calls, `head()`, `send()` and `getConfig()`.
