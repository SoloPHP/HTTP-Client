<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class HttpClient
{
    private Client $client;
    /** @var array<string, mixed> */
    private array $config;
    private int $maxRetries = 3;
    private int $retryDelay = 1000;
    /** @var callable|null */
    private mixed $logger = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(string $baseUri = '', array $config = [])
    {
        $this->config = array_merge([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'http_errors' => false,
        ], $config);

        $this->buildClient();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(string $baseUri = '', array $config = []): self
    {
        return new self($baseUri, $config);
    }

    public function timeout(int $seconds): self
    {
        $this->config['timeout'] = $seconds;
        $this->buildClient();
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        /** @var array<string, string> */
        $existingHeaders = $this->config['headers'] ?? [];
        $this->config['headers'] = array_merge($existingHeaders, $headers);
        $this->buildClient();
        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        $this->config['auth'] = [$username, $password];
        $this->buildClient();
        return $this;
    }

    public function retry(int $maxRetries = 3, int $delayMs = 1000): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $delayMs;
        $this->buildClient();
        return $this;
    }

    /**
     * @param LoggerInterface|callable|null $logger
     */
    public function withLogging(LoggerInterface|callable|null $logger = null): self
    {
        if ($logger instanceof LoggerInterface) {
            $this->logger = function (string $message) use ($logger): void {
                $logger->info($message);
            };
        } elseif (is_callable($logger)) {
            $this->logger = $logger;
        } else {
            $this->logger = function (string $message): void {
                error_log("[HttpClient] {$message}");
            };
        }

        $this->buildClient();
        return $this;
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
    public function postAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('POST', $uri, $options);
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this->client, $name)) {
            try {
                $response = $this->client->{$name}(...$arguments);

                if ($response instanceof ResponseInterface) {
                    return new HttpResponse($response);
                }

                if ($response instanceof PromiseInterface) {
                    return $response->then(function ($result) {
                        return $result instanceof ResponseInterface ? new HttpResponse($result) : $result;
                    });
                }

                return $response;
            } catch (GuzzleException $e) {
                throw new \RuntimeException(
                    "HTTP request failed: {$e->getMessage()}",
                    $e->getCode(),
                    $e
                );
            }
        }

        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $uri, array $options = []): HttpResponse
    {
        try {
            $response = $this->client->request($method, $uri, $options);
            return new HttpResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "HTTP request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options)
            ->then(function (ResponseInterface $response) {
                return new HttpResponse($response);
            });
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    private function buildClient(): void
    {
        $stack = HandlerStack::create();

        if ($this->maxRetries > 0) {
            $stack->push($this->createRetryMiddleware());
        }

        if ($this->logger) {
            $stack->push($this->createLoggingMiddleware());
        }

        $this->client = new Client(array_merge($this->config, [
            'handler' => $stack
        ]));
    }

    private function createRetryMiddleware(): callable
    {
        return Middleware::retry(
            function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Throwable $exception = null
            ) {
                if ($retries >= $this->maxRetries) {
                    return false;
                }

                if ($exception) {
                    return true;
                }

                if ($response) {
                    $status = $response->getStatusCode();
                    return $status === 429 || $status >= 500;
                }

                return false;
            },
            function (int $retries, ?ResponseInterface $response = null, ?RequestInterface $request = null) {
                if ($response && $response->hasHeader('Retry-After')) {
                    return (int) $response->getHeaderLine('Retry-After') * 1000;
                }

                $delay = $this->retryDelay * pow(2, $retries);
                $jitter = mt_rand(0, (int)($delay * 0.1));

                return $delay + $jitter;
            }
        );
    }

    private function createLoggingMiddleware(): callable
    {
        $logger = $this->logger;
        if ($logger === null) {
            throw new \RuntimeException('Logger is not set');
        }

        return Middleware::log(
            new class ($logger) implements LoggerInterface {
                /** @var callable */
                private $logger;

                /**
                 * @param callable $logger
                 */
                public function __construct(callable $logger)
                {
                    $this->logger = $logger;
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function emergency(string|\Stringable $message, array $context = []): void
                {
                    $this->log('emergency', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function alert(string|\Stringable $message, array $context = []): void
                {
                    $this->log('alert', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function critical(string|\Stringable $message, array $context = []): void
                {
                    $this->log('critical', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function error(string|\Stringable $message, array $context = []): void
                {
                    $this->log('error', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function warning(string|\Stringable $message, array $context = []): void
                {
                    $this->log('warning', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function notice(string|\Stringable $message, array $context = []): void
                {
                    $this->log('notice', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function info(string|\Stringable $message, array $context = []): void
                {
                    $this->log('info', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function debug(string|\Stringable $message, array $context = []): void
                {
                    $this->log('debug', $message, $context);
                }

                /**
                 * @param array<string, mixed> $context
                 */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    ($this->logger)((string)$message);
                }
            },
            new MessageFormatter()
        );
    }
}
