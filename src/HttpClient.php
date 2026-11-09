<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\NetworkTimeoutException as GuzzleNetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException as GuzzleResponseTimeoutException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\UriComparator;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\NetworkTimeoutException;
use Solo\HttpClient\Exception\RequestException;
use Solo\HttpClient\Exception\ResponseTimeoutException;
use Solo\HttpClient\Internal\CallbackLogger;
use Solo\HttpClient\Internal\Headers;
use Solo\HttpClient\Internal\Multipart;
use Solo\HttpClient\Internal\PublicHostGuard;
use Solo\HttpClient\Internal\RedactingFormatter;
use Solo\HttpClient\Internal\Redactor;
use Solo\HttpClient\Internal\ResponseSizeLimit;
use Solo\HttpClient\Internal\RetryPolicy;

/**
 * Immutable: timeout(), retry() and with*() return a new instance and never change the current one.
 *
 * Parameters that can carry secrets (URIs, options, headers, credentials) are marked #[\SensitiveParameter],
 * so stack traces show them as SensitiveParameterValue even when zend.exception_ignore_args is off.
 */
class HttpClient implements ClientInterface
{
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
    private bool $publicHostsOnly = false;
    /** @var (callable(string): list<string>)|null Host resolver of withPublicHostsOnly(); null for DNS */
    private $hostResolver = null;
    /** Limit of withMaxResponseSize() in bytes; null while it is off */
    private ?int $maxResponseSize = null;

    /**
     * @param array<string, mixed> $config Guzzle options; a custom "handler" is honored
     */
    public function __construct(
        #[\SensitiveParameter] string $baseUri = '',
        #[\SensitiveParameter] array $config = []
    ) {
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
    public static function create(
        #[\SensitiveParameter] string $baseUri = '',
        #[\SensitiveParameter] array $config = []
    ): self {
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
    public function withHeaders(#[\SensitiveParameter] array $headers): static
    {
        $clone = clone $this;
        $clone->applyHeaders($headers);
        return $clone;
    }

    /**
     * The token is sent only to the origin of base_uri (or everywhere when base_uri is empty).
     */
    public function withToken(#[\SensitiveParameter] string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    /**
     * Credentials are sent only to the origin of base_uri (or everywhere when base_uri is empty).
     */
    public function withBasicAuth(
        #[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password
    ): static {
        return $this->withHeaders(['Authorization' => self::basicAuthorization($username, $password)]);
    }

    /**
     * Retries are off by default. GET, HEAD, OPTIONS, TRACE, PUT and DELETE are retried on network errors,
     * 429 and 5xx. POST and PATCH are retried only on 429 or when the connection was never established,
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
            ? RetryPolicy::middleware($maxRetries, $delayMs, $maxDelayMs, $retryNonIdempotent)
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
        $clone->logger = $logger instanceof LoggerInterface ? $logger : new CallbackLogger($logger);
        return $clone;
    }

    /**
     * Mask values (e.g. a token embedded in the URL path) in logs and exception messages.
     */
    public function withRedaction(#[\SensitiveParameter] string ...$secrets): static
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
     * Sends requests only to public IP addresses, for URLs that come from users. A host that is not a public
     * address and does not resolve to one is refused with BlockedHostException before anything is sent. Every
     * redirect hop and every retry is checked, and the connection is pinned to the checked address with
     * CURLOPT_CONNECT_TO, so DNS rebinding cannot swap in another one. Requires the cURL extension.
     *
     * @param (callable(string): list<string>)|null $resolver Returns the IP addresses of a host name; by default
     *     its A records from DNS, and its AAAA records when no A record is public
     */
    public function withPublicHostsOnly(?callable $resolver = null): static
    {
        if (!\defined('CURLOPT_CONNECT_TO')) {
            throw new \LogicException('withPublicHostsOnly() requires the cURL extension');
        }

        $clone = clone $this;
        $clone->publicHostsOnly = true;
        $clone->hostResolver = $resolver;
        return $clone;
    }

    /**
     * Refuses a response whose body is larger than $bytes with ResponseTooLargeException: by its Content-Length as
     * soon as the headers arrive, otherwise once the body goes past the limit, counted after decoding. A refused
     * response is not retried.
     */
    public function withMaxResponseSize(int $bytes): static
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('The response size limit must not be negative');
        }

        $clone = clone $this;
        $clone->maxResponseSize = $bytes;
        return $clone;
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function postMultipart(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $data
    ): HttpResponse {
        return $this->request('POST', $uri, ['multipart' => Multipart::fields($data)]);
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function putMultipart(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $data
    ): HttpResponse {
        return $this->request('PUT', $uri, ['multipart' => Multipart::fields($data)]);
    }

    /**
     * @param array<array-key, mixed> $data Scalars, Stringable, streams, File objects or nested arrays
     */
    public function patchMultipart(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $data
    ): HttpResponse {
        return $this->request('PATCH', $uri, ['multipart' => Multipart::fields($data)]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $options = []): HttpResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function head(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $options = []): HttpResponse
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $options = []): HttpResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function put(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $options = []): HttpResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patch(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $options = []): HttpResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function delete(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): HttpResponse {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('GET', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function headAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('HEAD', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function postAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('POST', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function putAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('PUT', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patchAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('PATCH', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function deleteAsync(
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->requestAsync('DELETE', $uri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(
        string $method,
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): HttpResponse {
        return self::await($this->requestAsync($method, $uri, array_merge($options, ['synchronous' => true])));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function requestAsync(
        string $method,
        #[\SensitiveParameter] string $uri,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->dispatch(
            static function (
                Client $client,
                #[\SensitiveParameter] array $options
            ) use (
                $method,
                $uri
            ): PromiseInterface {
                // Guzzle validates the request options when it builds the request.
                // @phpstan-ignore argument.type
                return $client->requestAsync($method, $uri, $options);
            },
            $options
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(
        #[\SensitiveParameter] RequestInterface $request,
        #[\SensitiveParameter] array $options = []
    ): HttpResponse {
        return self::await($this->sendAsync($request, array_merge($options, ['synchronous' => true])));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendAsync(
        #[\SensitiveParameter] RequestInterface $request,
        #[\SensitiveParameter] array $options = []
    ): PromiseInterface {
        return $this->dispatch(
            static function (
                Client $client,
                #[\SensitiveParameter] array $options
            ) use (
                $request
            ): PromiseInterface {
                // Guzzle validates the request options when it sends the request.
                // @phpstan-ignore argument.type
                return $client->sendAsync($request, $options);
            },
            $options
        );
    }

    /**
     * PSR-18: returns the response for any status code; throws only on transfer errors.
     */
    #[\Override]
    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        return $this->send($request, ['allow_redirects' => false, 'http_errors' => false])->toPsrResponse();
    }

    /**
     * Proxies Guzzle's Client::getConfig().
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
    public function __call(string $name, #[\SensitiveParameter] array $arguments): never
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

        // Outermost layer: every failure leaves the stack already redacted, including the previous chain,
        // so it is safe to log even when taken from getClient() directly.
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

        if ($this->maxResponseSize !== null) {
            // Inside retries, so every attempt counts from zero, and a refusal reaches them as it is.
            $stack->push(ResponseSizeLimit::middleware($this->maxResponseSize), 'solo_max_response_size');
        }

        if ($this->publicHostsOnly) {
            // Innermost layer: every attempt of every redirect hop is checked and pinned as it reaches the handler.
            $stack->push(PublicHostGuard::middleware($this->hostResolver, $this->redactions), 'solo_public_hosts');
        }

        // Guzzle validates the client options when the client is built.
        // @phpstan-ignore argument.type
        return new Client(array_merge($this->config, ['handler' => $stack]));
    }

    /**
     * Single path of every request: checks per-request headers, prepares the multipart option, turns
     * synchronous throws and rejections into the client's exceptions, and responses into HttpResponse.
     *
     * @param callable(Client, array<string, mixed>): PromiseInterface $start
     * @param array<string, mixed> $options
     */
    private function dispatch(callable $start, #[\SensitiveParameter] array $options): PromiseInterface
    {
        if (isset($options['headers']) && is_array($options['headers'])) {
            Headers::validate($options['headers']);
        }
        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $options['multipart'] = Multipart::prepare($options['multipart']);
        }

        $redactions = $this->redactions;

        try {
            $promise = $start($this->client(), $options);
        } catch (GuzzleException $e) {
            $promise = Create::rejectionFor($e);
        } catch (MalformedUriException $e) {
            // A URL that does not parse is a failed request: URLs often come from users or from configuration.
            $promise = Create::rejectionFor(new HttpClientException(
                'HTTP request failed: ' . Redactor::message($e->getMessage(), $redactions),
                0,
                Redactor::exception($e, $redactions)
            ));
        }

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
    private function applyHeaders(#[\SensitiveParameter] array $headers): void
    {
        /** @var array<string, string|list<string>> $existing */
        $existing = $this->config['headers'] ?? [];

        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $values = Headers::values($name, $value);
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

        if ($type === 'basic') {
            $this->applyHeaders(['Authorization' => self::basicAuthorization($auth[0], $auth[1])]);
        } elseif (($type === 'digest' || $type === 'ntlm') && \defined('CURLOPT_HTTPAUTH')) {
            $this->authorization = null;
            $this->curlAuth = [$type === 'digest' ? \CURLAUTH_DIGEST : \CURLAUTH_NTLM, "{$auth[0]}:{$auth[1]}"];
        } else {
            return;
        }

        unset($this->config['auth']);
    }

    /**
     * RFC 7617: the user-id cannot contain a colon, and neither part can contain control characters.
     * Guzzle applies the same rule to its "auth" option.
     */
    private static function basicAuthorization(
        #[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password
    ): string {
        if (str_contains($username, ':') || preg_match('/[\x00-\x1F\x7F]/', $username . $password) !== 0) {
            throw new \InvalidArgumentException(
                'Basic auth credentials must not contain control characters, and the username must not contain a colon'
            );
        }

        return 'Basic ' . base64_encode("{$username}:{$password}");
    }

    /**
     * @param list<string>|null $authorization
     * @param array{int, string}|null $curlAuth
     */
    private static function createAuthMiddleware(
        #[\SensitiveParameter] ?array $authorization,
        #[\SensitiveParameter] ?array $curlAuth,
        ?UriInterface $origin
    ): callable {
        return static fn(callable $handler): callable => static function (
            #[\SensitiveParameter] RequestInterface $request,
            #[\SensitiveParameter] array $options
        ) use (
            $handler,
            $authorization,
            $curlAuth,
            $origin
        ) {
            $sameOrigin = $origin === null || !UriComparator::isCrossOrigin($origin, $request->getUri());
            // Credentials of the request itself, an Authorization header or the "auth" option, take precedence.
            $ownCredentials = $request->hasHeader('Authorization') || isset($options['auth']);

            if ($sameOrigin && !$ownCredentials) {
                if ($authorization !== null) {
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
    private static function createRedactionMiddleware(#[\SensitiveParameter] array $redactions): callable
    {
        return static fn(callable $handler): callable => static function (
            #[\SensitiveParameter] RequestInterface $request,
            #[\SensitiveParameter] array $options
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
                $reason instanceof TransferException ? Redactor::exception($reason, $redactions) : $reason
            ));
        };
    }

    /**
     * Transfer errors arrive already redacted by the "solo_redact" middleware; the message is redacted
     * again for the ones that never passed it (thrown before the request started, or left untouched
     * because of an unexpected constructor). Guzzle's timeouts become the timeout classes; otherwise the PSR-18
     * interfaces decide: Guzzle's NetworkException (no response) becomes NetworkException, its
     * RequestException RequestException.
     *
     * @param array<array-key, string> $redactions
     */
    private static function wrapException(
        GuzzleException $e,
        #[\SensitiveParameter] array $redactions
    ): HttpClientException {
        $code = (int) $e->getCode();
        $message = 'HTTP request failed: ' . Redactor::message($e->getMessage(), $redactions);

        return match (true) {
            $e instanceof ConnectTimeoutException, $e instanceof GuzzleNetworkTimeoutException
                => new NetworkTimeoutException($e->getRequest(), $message, $code, $e),
            $e instanceof GuzzleResponseTimeoutException
                => new ResponseTimeoutException($e->getRequest(), $message, $code, $e),
            $e instanceof NetworkExceptionInterface => new NetworkException($e->getRequest(), $message, $code, $e),
            $e instanceof RequestExceptionInterface => new RequestException($e->getRequest(), $message, $code, $e),
            default => new HttpClientException($message, $code, $e),
        };
    }
}
