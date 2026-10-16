<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\UriComparator;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\RequestException;
use Solo\HttpClient\Internal\HeaderParam;
use Solo\HttpClient\Internal\RedactingFormatter;
use Solo\HttpClient\Internal\Redactor;

/**
 * Immutable: timeout(), retry() and with*() return a new instance and never change the current one.
 */
class HttpClient implements ClientInterface
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'];

    /** Built on first use; every clone starts without one, so a chain of with*() calls builds a single client. */
    private ?Client $client = null;
    /** @var array<string, mixed> */
    private array $config;
    /** @var callable Created once and shared by all clones, so connections are reused across copies */
    private $handler;
    /** Origin that client-wide credentials are bound to; null when base_uri is not set */
    private ?UriInterface $origin;
    /** @var list<array{callable, string}> */
    private array $middlewares = [];
    /** @var list<string>|null Client-wide Authorization header */
    private ?array $authorization = null;
    /** @var array{int, string}|null Client-wide digest/NTLM credentials (CURLOPT_HTTPAUTH, CURLOPT_USERPWD) */
    private ?array $curlAuth = null;
    /** @var callable|null */
    private $retryMiddleware = null;
    private ?LoggerInterface $logger = null;
    /** @var array<array-key, string> Secrets to mask in logs and exception messages, for strtr() */
    private array $redactions = [];

    /**
     * @param array<string, mixed> $config Guzzle options; a custom "handler" is honored
     */
    public function __construct(string $baseUri = '', array $config = [])
    {
        $config = array_merge([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'http_errors' => false,
        ], $config);

        $handler = $config['handler'] ?? Utils::chooseHandler();
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('Handler must be callable');
        }
        $headers = $config['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('Headers must be an array');
        }
        unset($config['handler'], $config['headers']);

        $baseUri = $config['base_uri'];
        $this->origin = ($baseUri instanceof UriInterface || is_string($baseUri)) && (string) $baseUri !== ''
            ? Psr7Utils::uriFor($baseUri)
            : null;
        $this->handler = $handler;
        $this->config = $config;
        $this->applyHeaders($headers);
        $this->extractAuthOption();

        // Built eagerly here (unlike clones), so invalid config fails at construction.
        $this->client();
    }

    public function __clone()
    {
        $this->client = null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(string $baseUri = '', array $config = []): self
    {
        return new self($baseUri, $config);
    }

    public function timeout(int|float $seconds): static
    {
        $clone = clone $this;
        $clone->config['timeout'] = $seconds;
        return $clone;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        $clone->applyHeaders($headers);
        return $clone;
    }

    /**
     * The token is sent only to the origin of base_uri (or everywhere when base_uri is empty).
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    /**
     * Credentials are sent only to the origin of base_uri (or everywhere when base_uri is empty).
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withToken(base64_encode("{$username}:{$password}"), 'Basic');
    }

    /**
     * Retries are off by default. GET, HEAD, OPTIONS, TRACE, PUT and DELETE are retried on network errors,
     * 429 and 5xx. POST and PATCH are retried only on 429 or when the request was never sent,
     * unless $retryNonIdempotent is true (use it only with idempotency keys).
     */
    public function retry(
        int $maxRetries = 3,
        int $delayMs = 1000,
        int $maxDelayMs = 30000,
        bool $retryNonIdempotent = false
    ): static {
        if ($maxRetries < 0 || $delayMs < 0 || $maxDelayMs < 0) {
            throw new \InvalidArgumentException('Retry parameters must not be negative');
        }

        $clone = clone $this;
        $clone->retryMiddleware = $maxRetries > 0
            ? self::createRetryMiddleware($maxRetries, $delayMs, $maxDelayMs, $retryNonIdempotent)
            : null;
        return $clone;
    }

    /**
     * A PSR-3 logger receives "info" for responses and "error" for failed transfers.
     * Query values and URI credentials are redacted; secrets in the URL path need withRedaction().
     */
    public function withLogging(LoggerInterface|callable|null $logger = null): static
    {
        $clone = clone $this;
        $clone->logger = $logger instanceof LoggerInterface ? $logger : self::callableLogger($logger);
        return $clone;
    }

    /**
     * Mask values (e.g. a token embedded in the URL path) in logs and exception messages.
     */
    public function withRedaction(string ...$secrets): static
    {
        $clone = clone $this;
        $clone->redactions += Redactor::redactions(...$secrets);
        return $clone;
    }

    /**
     * Add a Guzzle middleware (see GuzzleHttp\Middleware).
     */
    public function withMiddleware(callable $middleware, string $name = ''): static
    {
        $clone = clone $this;
        $clone->middlewares[] = [$middleware, $name];
        return $clone;
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function postMultipart(string $uri, array $data): HttpResponse
    {
        return $this->request('POST', $uri, ['multipart' => self::multipartFields($data)]);
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function putMultipart(string $uri, array $data): HttpResponse
    {
        return $this->request('PUT', $uri, ['multipart' => self::multipartFields($data)]);
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function patchMultipart(string $uri, array $data): HttpResponse
    {
        return $this->request('PATCH', $uri, ['multipart' => self::multipartFields($data)]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $uri, array $options = []): HttpResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function head(string $uri, array $options = []): HttpResponse
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $uri, array $options = []): HttpResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function put(string $uri, array $options = []): HttpResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patch(string $uri, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function delete(string $uri, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('GET', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function headAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('HEAD', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function postAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('POST', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function putAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('PUT', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patchAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('PATCH', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function deleteAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('DELETE', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $uri, array $options = []): HttpResponse
    {
        return self::await($this->requestAsync($method, $uri, array_merge($options, ['synchronous' => true])));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
    {
        return $this->dispatch(
            static fn(Client $client, array $options) => $client->requestAsync($method, $uri, $options),
            $options
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(RequestInterface $request, array $options = []): HttpResponse
    {
        return self::await($this->sendAsync($request, array_merge($options, ['synchronous' => true])));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->dispatch(
            static fn(Client $client, array $options) => $client->sendAsync($request, $options),
            $options
        );
    }

    /**
     * PSR-18: returns the response for any status code; throws only on transfer errors.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request, ['allow_redirects' => false, 'http_errors' => false])->toPsrResponse();
    }

    /**
     * Mirrors Guzzle's Client::getConfig(), which Guzzle 8 removes.
     */
    public function getConfig(?string $option = null): mixed
    {
        return $this->client()->getConfig($option);
    }

    /**
     * Guzzle's magic request methods (e.g. $client->options()) are not proxied: they would bypass
     * exception wrapping and accept any string as an HTTP method.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): never
    {
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    public function getClient(): Client
    {
        return $this->client();
    }

    private function client(): Client
    {
        return $this->client ??= $this->buildClient();
    }

    private function buildClient(): Client
    {
        $stack = $this->handler instanceof HandlerStack
            ? clone $this->handler
            : HandlerStack::create($this->handler);

        if ($this->authorization !== null || $this->curlAuth !== null) {
            // Outer layer: credentials are added once, to the initial request only, so Guzzle's
            // redirect middleware strips them on cross-origin hops.
            $stack->unshift(
                self::createAuthMiddleware($this->authorization, $this->curlAuth, $this->origin),
                'solo_auth'
            );
        }

        // Outermost layer: every failure leaves the stack already redacted, including the previous chain
        // and handler context, so it is safe to log even when taken from getClient() directly.
        $stack->unshift(self::createRedactionMiddleware($this->redactions), 'solo_redact');

        foreach ($this->middlewares as [$middleware, $name]) {
            $stack->push($middleware, $name);
        }

        if ($this->retryMiddleware !== null) {
            $stack->push($this->retryMiddleware, 'solo_retry');
        }

        if ($this->logger !== null) {
            $stack->push(Middleware::log($this->logger, new RedactingFormatter($this->redactions)), 'solo_log');
        }

        return new Client(array_merge($this->config, ['handler' => $stack]));
    }

    /**
     * Single path of every request: prepares the multipart option, turns synchronous throws and
     * rejections into the client's exceptions, and responses into HttpResponse.
     *
     * @param callable(Client, array<string, mixed>): PromiseInterface $start
     * @param array<string, mixed> $options
     */
    private function dispatch(callable $start, array $options): PromiseInterface
    {
        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $options['multipart'] = self::prepareMultipart($options['multipart']);
        }

        try {
            $promise = $start($this->client(), $options);
        } catch (GuzzleException $e) {
            $promise = Create::rejectionFor($e);
        }

        $redactions = $this->redactions;

        return $promise->then(
            static fn(mixed $result): mixed => $result instanceof ResponseInterface
                ? new HttpResponse($result)
                : $result,
            static function (mixed $reason) use ($redactions): never {
                if ($reason instanceof GuzzleException) {
                    throw self::wrapException($reason, $redactions);
                }
                if ($reason instanceof \Throwable) {
                    throw $reason;
                }
                throw new HttpClientException('HTTP request failed: ' . get_debug_type($reason));
            }
        );
    }

    private static function await(PromiseInterface $promise): HttpResponse
    {
        /** @var HttpResponse $response fulfilled by dispatch() */
        $response = $promise->wait();
        return $response;
    }

    /**
     * Validates headers and merges them into config case-insensitively. Authorization becomes the
     * client-wide credential bound to the base_uri origin and replaces digest/NTLM credentials.
     *
     * @param array<array-key, mixed> $headers
     */
    private function applyHeaders(array $headers): void
    {
        /** @var array<string, string|list<string>> $existing */
        $existing = $this->config['headers'] ?? [];

        foreach ($headers as $name => $value) {
            $name = self::assertHeaderName((string) $name);
            $values = self::assertHeaderValues($name, $value);
            if (strcasecmp($name, 'Authorization') === 0) {
                $this->authorization = $values;
                $this->curlAuth = null;
                continue;
            }
            $existing = Psr7Utils::caselessRemove([$name], $existing);
            $existing[$name] = count($values) === 1 ? $values[0] : $values;
        }

        $this->config['headers'] = $existing;
    }

    /**
     * Moves the "auth" option to the auth middleware, so it is bound to the base_uri origin.
     */
    private function extractAuthOption(): void
    {
        $auth = $this->config['auth'] ?? null;
        if (!is_array($auth) || !is_string($auth[0] ?? null) || !is_string($auth[1] ?? null)) {
            return;
        }

        $type = $auth[2] ?? 'basic';
        $type = is_string($type) ? strtolower($type) : '';
        $credentials = "{$auth[0]}:{$auth[1]}";

        if ($type === 'basic') {
            $this->applyHeaders(['Authorization' => 'Basic ' . base64_encode($credentials)]);
        } elseif (($type === 'digest' || $type === 'ntlm') && \defined('CURLOPT_HTTPAUTH')) {
            $this->authorization = null;
            $this->curlAuth = [$type === 'digest' ? \CURLAUTH_DIGEST : \CURLAUTH_NTLM, $credentials];
        } else {
            return;
        }

        unset($this->config['auth']);
    }

    /**
     * @param list<string>|null $authorization
     * @param array{int, string}|null $curlAuth
     */
    private static function createAuthMiddleware(
        ?array $authorization,
        ?array $curlAuth,
        ?UriInterface $origin
    ): callable {
        return static fn(callable $handler): callable => static function (
            RequestInterface $request,
            array $options
        ) use (
            $handler,
            $authorization,
            $curlAuth,
            $origin
        ) {
            if ($origin === null || !UriComparator::isCrossOrigin($origin, $request->getUri())) {
                // Per-request credentials (an Authorization header or the "auth" option) take precedence.
                if ($authorization !== null && !$request->hasHeader('Authorization')) {
                    $request = $request->withHeader('Authorization', $authorization);
                }

                $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
                if ($curlAuth !== null && !isset($curl[\CURLOPT_HTTPAUTH])) {
                    $curl[\CURLOPT_HTTPAUTH] = $curlAuth[0];
                    $curl[\CURLOPT_USERPWD] = $curlAuth[1];
                    $options['curl'] = $curl;
                }
            }

            return $handler($request, $options);
        };
    }

    /**
     * @param array<array-key, string> $redactions
     */
    private static function createRedactionMiddleware(array $redactions): callable
    {
        return static fn(callable $handler): callable => static function (
            RequestInterface $request,
            array $options
        ) use (
            $handler,
            $redactions
        ): PromiseInterface {
            try {
                $promise = Create::promiseFor($handler($request, $options));
            } catch (\Exception $e) {
                $promise = Create::rejectionFor($e);
            }

            // Only transfer exceptions are rebuilt (keeping their class); any other reason keeps its class
            // and is redacted by wrapException().
            return $promise->then(null, static fn(mixed $reason): PromiseInterface => Create::rejectionFor(
                $reason instanceof ConnectException || $reason instanceof GuzzleRequestException
                    ? Redactor::exception($reason, $redactions)
                    : $reason
            ));
        };
    }

    private static function createRetryMiddleware(
        int $maxRetries,
        int $baseDelay,
        int $maxDelay,
        bool $retryNonIdempotent
    ): callable {
        return Middleware::retry(
            static function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Throwable $exception = null
            ) use (
                $maxRetries,
                $retryNonIdempotent
            ): bool {
                // A consumed non-seekable body cannot be replayed: a retry would send it empty.
                if ($retries >= $maxRetries || !$request->getBody()->isSeekable()) {
                    return false;
                }

                $idempotent = $retryNonIdempotent
                    || in_array(strtoupper($request->getMethod()), self::IDEMPOTENT_METHODS, true);

                if ($response === null && $exception instanceof GuzzleRequestException) {
                    $response = $exception->getResponse();
                }

                if ($response !== null) {
                    $status = $response->getStatusCode();
                    // 429 means the request was rejected without processing.
                    return $status === 429 || ($idempotent && $status >= 500);
                }

                if ($exception === null) {
                    return false;
                }

                return $idempotent || self::wasNotSent($exception);
            },
            static function (int $retries, ?ResponseInterface $response = null) use ($baseDelay, $maxDelay): int {
                $retryAfter = $response !== null ? self::parseRetryAfter($response) : null;
                if ($retryAfter !== null) {
                    return min($retryAfter, $maxDelay);
                }

                $delay = (int) min($maxDelay, $baseDelay * 2 ** min($retries - 1, 30));
                $delay += random_int(0, intdiv($delay, 10));

                return min($delay, $maxDelay);
            }
        );
    }

    /**
     * cURL errors raised before anything was sent (host not resolved, connection refused, TLS handshake):
     * the only failures that are safe to retry for POST/PATCH.
     */
    private static function wasNotSent(\Throwable $exception): bool
    {
        return $exception instanceof ConnectException
            && \defined('CURLE_COULDNT_CONNECT')
            && in_array(
                $exception->getHandlerContext()['errno'] ?? null,
                [\CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT, \CURLE_SSL_CONNECT_ERROR],
                true
            );
    }

    /**
     * @return int|null Delay in milliseconds, or null when the header is absent or invalid
     */
    private static function parseRetryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $value) === 1) {
            return (int) $value * 1000;
        }

        // IMF-fixdate, e.g. "Wed, 21 Oct 2015 07:28:00 GMT"
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \\G\\M\\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - time()) * 1000;
    }

    /**
     * Flattens a field map ("tags" => ["a"] becomes "tags[0]") into multipart parts. No I/O happens here.
     *
     * @param array<array-key, mixed> $data
     * @return list<array{name: string, contents: mixed}>
     */
    private static function multipartFields(array $data, string $prefix = ''): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                array_push($fields, ...self::multipartFields($value, $name));
            } elseif (
                $value instanceof File
                || $value instanceof StreamInterface
                || is_resource($value)
                || is_scalar($value)
                || $value === null
            ) {
                $fields[] = ['name' => $name, 'contents' => $value];
            } elseif ($value instanceof \Stringable) {
                $fields[] = ['name' => $name, 'contents' => (string) $value];
            } else {
                throw new \InvalidArgumentException(
                    'Unsupported value for multipart field "' . HeaderParam::encode($name) . '": '
                    . get_debug_type($value)
                );
            }
        }

        return $fields;
    }

    /**
     * Applied to the "multipart" option of every request. Validates all parts before any File stream
     * is opened, expands nested arrays, percent-encodes names and file names, and validates part headers.
     *
     * @param array<array-key, mixed> $parts
     * @return list<array<string, mixed>>
     */
    private static function prepareMultipart(array $parts): array
    {
        /** @var list<array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}> */
        $fields = [];

        foreach ($parts as $part) {
            $name = is_array($part) ? ($part['name'] ?? null) : null;
            if (!is_array($part) || !(is_string($name) || is_int($name)) || !array_key_exists('contents', $part)) {
                throw new \InvalidArgumentException('Each multipart part needs a "name" and "contents"');
            }

            if (is_array($part['contents'])) {
                if (isset($part['filename']) || isset($part['headers'])) {
                    throw new \InvalidArgumentException('"filename" and "headers" cannot be used with array contents');
                }
                array_push($fields, ...self::multipartFields($part['contents'], (string) $name));
                continue;
            }

            $field = ['name' => (string) $name, 'contents' => $part['contents']];
            if (isset($part['filename'])) {
                if (!is_string($part['filename'])) {
                    throw new \InvalidArgumentException('Multipart "filename" must be a string');
                }
                $field['filename'] = $part['filename'];
            }
            if (isset($part['headers'])) {
                if (!is_array($part['headers'])) {
                    throw new \InvalidArgumentException('Multipart "headers" must be an array');
                }
                $field['headers'] = [];
                foreach ($part['headers'] as $headerName => $value) {
                    $headerName = self::assertHeaderName((string) $headerName);
                    $field['headers'][$headerName] = implode(', ', self::assertHeaderValues($headerName, $value));
                }
            }
            $fields[] = $field;
        }

        return array_map(
            /** @param array{name: string, contents: mixed, filename?: string, headers?: array<string, string>} $part */
            static function (array $part): array {
                $file = $part['contents'];
                if ($file instanceof File) {
                    $part['contents'] = $file->openStream();
                    $part['filename'] ??= $file->filename();
                    if ($file->mimeType() !== null) {
                        $part['headers'] = ['Content-Type' => $file->mimeType()] + ($part['headers'] ?? []);
                    }
                }

                $part['name'] = HeaderParam::encode($part['name']);
                if (isset($part['filename'])) {
                    $part['filename'] = HeaderParam::encode($part['filename']);
                }

                return $part;
            },
            $fields
        );
    }

    private static function assertHeaderName(string $name): string
    {
        if (preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid header name');
        }

        return $name;
    }

    /**
     * Validates values by the guzzlehttp/psr7 rule up front, so invalid values fail here
     * instead of at request time, and the message never contains the (possibly secret) value.
     *
     * @return list<string>
     */
    private static function assertHeaderValues(string $name, mixed $value): array
    {
        $values = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_string($item) || preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $item) !== 1) {
                throw new \InvalidArgumentException(
                    "Header {$name} must be a string without control characters"
                );
            }
            $values[] = $item;
        }

        if ($values === []) {
            throw new \InvalidArgumentException("Header {$name} must not be empty");
        }

        return $values;
    }

    /**
     * Transfer errors arrive already redacted by the "solo_redact" middleware; the message is redacted
     * again for the ones that never passed it (thrown before the request started, or left untouched
     * because of an unexpected constructor).
     *
     * @param array<array-key, string> $redactions
     */
    private static function wrapException(GuzzleException $e, array $redactions): HttpClientException
    {
        $code = (int) $e->getCode();
        $message = 'HTTP request failed: ' . Redactor::message($e->getMessage(), $redactions);

        return match (true) {
            $e instanceof ConnectException => new NetworkException($e->getRequest(), $message, $code, $e),
            $e instanceof GuzzleRequestException => new RequestException($e->getRequest(), $message, $code, $e),
            default => new HttpClientException($message, $code, $e),
        };
    }

    private static function callableLogger(?callable $callback): LoggerInterface
    {
        return new class ($callback) extends AbstractLogger {
            /** @var callable|null */
            private $callback;

            public function __construct(?callable $callback)
            {
                $this->callback = $callback;
            }

            /**
             * @param mixed $level
             * @param array<mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($this->callback === null) {
                    $prefix = is_string($level) ? strtoupper($level) : 'INFO';
                    error_log("[HttpClient] {$prefix}: {$message}");
                    return;
                }

                ($this->callback)((string) $message);
            }
        };
    }
}
