<?php

namespace Solo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base HTTP client with retry functionality
 *
 * Provides basic HTTP client functionality with automatic retries
 * for network errors and rate limit restrictions.
 *
 * @method get(string $uri, array $options = [])
 * @method post(string $uri, array $options = [])
 * @method put(string $uri, array $options = [])
 * @method patch(string $uri, array $options = [])
 * @method delete(string $uri, array $options = [])
 * @method head(string $uri, array $options = [])
 * @method options(string $uri, array $options = [])
 * @method request(string $method, string $uri, array $options = [])
 * @method getAsync(string $uri, array $options = [])
 * @method postAsync(string $uri, array $options = [])
 * @method putAsync(string $uri, array $options = [])
 * @method patchAsync(string $uri, array $options = [])
 * @method deleteAsync(string $uri, array $options = [])
 * @method headAsync(string $uri, array $options = [])
 * @method optionsAsync(string $uri, array $options = [])
 * @method requestAsync(string $method, string $uri, array $options = [])
 */
abstract class AbstractHttpClient
{
    protected Client $client;

    /**
     * Maximum number of retry attempts
     */
    protected int $maxRetries = 3;

    /**
     * Last received response
     */
    private ResponseInterface|PromiseInterface $response;

    /**
     * Get the base URL for the API
     */
    abstract public function getBaseUrl(): string;

    /**
     * Handles HTTP client method calls
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @return mixed JSON decoded response or ResponseInterface
     * @throws \RuntimeException When HTTP request fails
     * @throws \BadMethodCallException When method doesn't exist
     */
    public function __call(string $name, array $arguments)
    {
        if (method_exists($this->client, $name)) {
            try {
                $this->response = $this->client->{$name}(...$arguments);
                $contents = json_decode($this->response->getBody()->getContents());
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $contents;
                }
                return $this->response;
            } catch (GuzzleException $e) {
                throw new \RuntimeException(
                    "Error occurred while making HTTP request: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        }
        throw new \BadMethodCallException("Method $name does not exist");
    }

    /**
     * Returns default client configuration with retry middleware
     *
     * @return array Client configuration
     */
    protected function getDefaultClientConfig(): array
    {
        $stack = HandlerStack::create();
        $stack->push($this->createRetryMiddleware($stack->resolve()));

        return ['handler' => $stack];
    }

    /**
     * Creates retry middleware for handling HTTP request retries
     *
     * The middleware will retry requests based on:
     * - Maximum number of retries not being exceeded
     * - Response status being 429 (Too Many Requests) or 5xx
     * - Using Retry-After header when available
     * - Falling back to exponential delay between retries
     *
     * @param callable $handler Request handler from the handler stack
     * @return callable Returns middleware function
     */
    protected function createRetryMiddleware(callable $handler): callable
    {
        $decider = $this->createDecider();
        $delay = $this->createDelay();

        return function (callable $handler) use ($decider, $delay) {
            return new RetryMiddleware($decider, $handler, $delay);
        };
    }

    /**
     * Creates retry decision function
     *
     * Determines if a request should be retried based on:
     * - Number of retries not exceeding max retries
     * - Response status being 429 (Too Many Requests) or 5xx
     *
     * @return callable
     */
    protected function createDecider(): callable
    {
        return function (
            int               $retries,
            RequestInterface  $request,
            ResponseInterface $response = null
        ) {
            return $retries < $this->maxRetries &&
                ($response && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500));
        };
    }

    /**
     * Creates delay function for retry attempts
     *
     * Uses Retry-After header if available, falls back to exponential delay
     *
     * @return callable
     */
    protected function createDelay(): callable
    {
        return function (int $retries, ResponseInterface $response = null) {
            if ($response && $response->hasHeader('Retry-After')) {
                return (int)$response->getHeaderLine('Retry-After') * 1000;
            }
            return RetryMiddleware::exponentialDelay($retries);
        };
    }

    /**
     * Initializes HTTP client with given configuration
     *
     * @param array $config Client configuration
     */
    protected function setClient(array $config): void
    {
        $this->client = new Client(array_merge($this->getDefaultClientConfig(), $config));
    }

    /**
     * Returns the last received response
     *
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Returns HTTP client instance
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Sets maximum number of retry attempts
     *
     * @param int $maxRetries Maximum number of retries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }
}