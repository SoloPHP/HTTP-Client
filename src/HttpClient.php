<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

class HttpClient
{
    private Client $client;
    private array $config;
    private int $maxRetries = 3;
    private int $retryDelay = 1000;
    private mixed $logger = null;

    public function __construct(string $baseUri = '', array $config = [])
    {
        $this->config = array_merge([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'http_errors' => false,
        ], $config);

        $this->buildClient();
    }

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

    public function withHeaders(array $headers): self
    {
        $this->config['headers'] = array_merge($this->config['headers'] ?? [], $headers);
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

    public function withLogging($logger = null): self
    {
        if ($logger instanceof \Psr\Log\LoggerInterface) {
            $this->logger = function ($message) use ($logger) {
                $logger->info($message);
            };
        } elseif (is_callable($logger)) {
            $this->logger = $logger;
        } else {
            $this->logger = function ($message) {
                error_log("[HttpClient] {$message}");
            };
        }

        $this->buildClient();
        return $this;
    }

    public function get(string $uri, array $options = []): HttpResponse
    {
        return $this->request('GET', $uri, $options);
    }

    public function post(string $uri, array $data = [], array $options = []): HttpResponse
    {
        if (!empty($data)) {
            $options['json'] = $data;
        }
        return $this->request('POST', $uri, $options);
    }

    public function put(string $uri, array $data = [], array $options = []): HttpResponse
    {
        if (!empty($data)) {
            $options['json'] = $data;
        }
        return $this->request('PUT', $uri, $options);
    }

    public function patch(string $uri, array $data = [], array $options = []): HttpResponse
    {
        if (!empty($data)) {
            $options['json'] = $data;
        }
        return $this->request('PATCH', $uri, $options);
    }

    public function delete(string $uri, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $uri, $options);
    }

    public function getAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('GET', $uri, $options);
    }

    public function postAsync(string $uri, array $data = [], array $options = []): PromiseInterface
    {
        if (!empty($data)) {
            $options['json'] = $data;
        }
        return $this->requestAsync('POST', $uri, $options);
    }

    public function __call(string $name, array $arguments)
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
            function (int $retries, ?\Throwable $exception = null, ?ResponseInterface $response = null) {
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
            function (int $retries, ?ResponseInterface $response = null) {
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
        return Middleware::log(
            new class ($this->logger) implements \Psr\Log\LoggerInterface {
                private $logger;

                public function __construct($logger)
                {
                    $this->logger = $logger;
                }

                public function emergency($message, array $context = []): void
                {
                    $this->log(LogLevel::EMERGENCY, $message, $context);
                }

                public function alert($message, array $context = []): void
                {
                    $this->log(LogLevel::ALERT, $message, $context);
                }

                public function critical($message, array $context = []): void
                {
                    $this->log(LogLevel::CRITICAL, $message, $context);
                }

                public function error($message, array $context = []): void
                {
                    $this->log(LogLevel::ERROR, $message, $context);
                }

                public function warning($message, array $context = []): void
                {
                    $this->log(LogLevel::WARNING, $message, $context);
                }

                public function notice($message, array $context = []): void
                {
                    $this->log(LogLevel::NOTICE, $message, $context);
                }

                public function info($message, array $context = []): void
                {
                    $this->log(LogLevel::INFO, $message, $context);
                }

                public function debug($message, array $context = []): void
                {
                    $this->log(LogLevel::DEBUG, $message, $context);
                }

                public function log(LogLevel $level, $message, array $context = []): void
                {
                    if (is_callable($this->logger)) {
                        ($this->logger)($message);
                    }
                }
            },
            new \GuzzleHttp\MessageFormatter()
        );
    }
}
