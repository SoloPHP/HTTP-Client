<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Solo\HttpClient\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class HttpClientExceptionTest extends TestCase
{
    public function testRequestWithConnectionException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP request failed:');

        $mock = new MockHandler([
            new ConnectException('Connection failed', new Request('GET', 'test'))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $httpClient->get('/test');
    }

    public function testRequestWithRequestException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP request failed:');

        $mock = new MockHandler([
            new RequestException('Request failed', new Request('GET', 'test'))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $httpClient->get('/test');
    }

    public function testCallNonExistentMethod(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Method nonexistentMethod does not exist');

        $httpClient = new HttpClient();
        $httpClient->nonexistentMethod();
    }

    public function testAsyncRequestWithException(): void
    {
        $this->expectException(\RuntimeException::class);

        $mock = new MockHandler([
            new ConnectException('Connection failed', new Request('GET', 'test'))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $httpClient->getAsync('/test')->wait();
    }

    public function testRequestWithTimeout(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP request failed:');

        $mock = new MockHandler([
            new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'test'))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $httpClient->get('/test');
    }

    public function testRequestWithServerError(): void
    {
        // Server error should not throw exception because http_errors = false
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"error": "Internal Server Error"}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $response = $httpClient->get('/test');

        $this->assertInstanceOf(\Solo\HttpClient\HttpResponse::class, $response);
        $this->assertEquals(500, $response->status());
        $this->assertTrue($response->serverError());
    }

    public function testRequestWithClientError(): void
    {
        // Client error should not throw exception because http_errors = false
        $mock = new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], '{"error": "Not Found"}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $response = $httpClient->get('/test');

        $this->assertInstanceOf(\Solo\HttpClient\HttpResponse::class, $response);
        $this->assertEquals(404, $response->status());
        $this->assertTrue($response->clientError());
    }
}
