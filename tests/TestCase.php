<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\HttpClient;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> Every attempt sent */
    protected array $sent = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * Innermost handler: records each attempt and drops "delay", so retry tests do not sleep.
     *
     * @param list<mixed> $queue MockHandler queue
     */
    protected function recordingHandler(array $queue): callable
    {
        $mock = new MockHandler($queue);

        return function (RequestInterface $request, array $options) use ($mock) {
            $this->sent[] = ['request' => $request, 'options' => $options];
            unset($options['delay']);
            return $mock($request, $options);
        };
    }

    /**
     * @param list<mixed> $queue
     * @param array<string, mixed> $config
     */
    protected function client(array $queue, string $baseUri = 'https://api.test', array $config = []): HttpClient
    {
        return new HttpClient($baseUri, ['handler' => $this->recordingHandler($queue)] + $config);
    }

    protected function sentRequest(int $attempt = 0): RequestInterface
    {
        return $this->sent[$attempt]['request'];
    }

    protected function sentBody(int $attempt = 0): string
    {
        return (string) $this->sentRequest($attempt)->getBody();
    }

    protected static function ok(string $body = 'ok'): Response
    {
        return new Response(200, [], $body);
    }

    /**
     * cURL-like ConnectException. errno 7 = could not connect (not sent), 28 = timeout (maybe processed).
     */
    protected static function connectError(int $errno): callable
    {
        return static fn(RequestInterface $request) => new ConnectException(
            "cURL error {$errno}: transfer failed for {$request->getUri()}",
            $request,
            null,
            ['errno' => $errno]
        );
    }

    protected function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'solo');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }
}
