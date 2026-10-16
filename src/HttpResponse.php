<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use Psr\Http\Message\ResponseInterface;

class HttpResponse
{
    private readonly ResponseInterface $response;
    private ?string $body = null;
    private bool $jsonDecoded = false;
    private mixed $jsonData = null;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function successful(): bool
    {
        $status = $this->status();
        return $status >= 200 && $status < 300;
    }

    public function ok(): bool
    {
        return $this->status() === 200;
    }

    public function clientError(): bool
    {
        $status = $this->status();
        return $status >= 400 && $status < 500;
    }

    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * Seekable bodies are re-read on each call; only non-seekable ones (which can be read once)
     * are cached, so large responses are not kept in memory twice.
     */
    public function body(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $stream = $this->response->getBody();
        $contents = (string) $stream;
        if (!$stream->isSeekable()) {
            $this->body = $contents;
        }

        return $contents;
    }

    /**
     * Decoded JSON body (array, scalar or null). Returns null when the body is not valid JSON,
     * so checks like `if ($response->json('allowed'))` fail closed.
     * With $key ("a.b.c"), returns the nested value or null.
     */
    public function json(?string $key = null): mixed
    {
        if (!$this->jsonDecoded) {
            $this->jsonDecoded = true;
            try {
                $this->jsonData = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Invalid JSON stays null, so checks like `if ($response->json('allowed'))` fail closed.
            }
        }

        if ($key === null) {
            return $this->jsonData;
        }

        return is_array($this->jsonData) ? $this->getNestedValue($this->jsonData, $key) : null;
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name)
            ? $this->response->getHeaderLine($name)
            : null;
    }

    /**
     * @return array<array<string>>
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function getNestedValue(array $data, string $key): mixed
    {
        $keys = explode('.', $key);
        $result = $data;

        foreach ($keys as $segment) {
            if (is_array($result) && array_key_exists($segment, $result)) {
                $result = $result[$segment];
            } else {
                return null;
            }
        }

        return $result;
    }

    public function __toString(): string
    {
        return $this->body();
    }
}
