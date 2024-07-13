<?php declare(strict_types=1);

namespace Solo\Http\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Request;

class ClientFactory
{
    private array $headers = [];
    private int $timeout = 15;
    private bool $sslCertificate = false;
    private array $files = [];

    public function withHeaders(array $headers): self
    {
        $this->headers += $headers;
        return $this;
    }

    public function withToken(string $token): self
    {
        $this->headers['Authorization'] = 'Bearer ' . $token;
        return $this;
    }

    public function withJson(): self
    {
        $this->headers['Content-Type'] = 'application/json';
        return $this;
    }

    public function withTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function withSslCertificate(bool $sslCertificate): self
    {
        $this->sslCertificate = $sslCertificate;
        return $this;
    }

    public function withFile(string $field, string $filepath, string $mimeType = ''): self
    {
        if (!file_exists($filepath)) {
            throw new \RuntimeException("File $filepath not exists");
        }

        if (empty($mimeType)) $mimeType = $this->detectFileMimeType($filepath);

        $this->files[$field] = '@' . $filepath . (!empty($mimeType) ? ';' . $mimeType : '');
        return $this;
    }

    public function get(string $uri): ResponseInterface
    {
        $request = new Request('GET', $uri);
        return $this->sendRequest($request);
    }

    public function post(string $uri, array $data = []): ResponseInterface
    {
        $body = $this->encode($data);
        $request = new Request('POST', $uri, [], $body);
        return $this->sendRequest($request);
    }

    public function put(string $uri, array $data = []): ResponseInterface
    {
        $body = $this->encode($data);
        $request = new Request('PUT', $uri, [], $body);
        return $this->sendRequest($request);
    }

    public function patch(string $uri, array $data = []): ResponseInterface
    {
        $body = $this->encode($data);
        $request = new Request('PATCH', $uri, [], $body);
        return $this->sendRequest($request);
    }

    public function delete(string $uri, array $data = []): ResponseInterface
    {
        $body = $this->encode($data);
        $request = new Request('DELETE', $uri, [], $body);
        return $this->sendRequest($request);
    }

    private function encode(array $data): string
    {
        $data = array_merge($data, $this->files);
        return json_encode($data) !== false ? json_encode($data) : '';
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $client = new Client($this->timeout, $this->sslCertificate);
        return $client->sendRequest($request);
    }

    private function detectFileMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($mimeType !== false) return $mimeType;
        return 'application/octet-stream';
    }
}