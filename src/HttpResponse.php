<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use Psr\Http\Message\ResponseInterface;

class HttpResponse
{
    private readonly ResponseInterface $response;
    private ?array $jsonData = null;

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

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    public function json(?string $key = null)
    {
        if ($this->jsonData === null) {
            $contents = $this->body();
            $this->jsonData = json_decode($contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->response;
            }
        }

        if ($key !== null) {
            return $this->getNestedValue($this->jsonData, $key);
        }

        return $this->jsonData;
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name)
            ? $this->response->getHeaderLine($name)
            : null;
    }

    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    private function getNestedValue(array $data, string $key)
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
