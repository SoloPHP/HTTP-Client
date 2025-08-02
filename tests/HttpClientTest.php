<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Solo\HttpClient\HttpClient;
use Solo\HttpClient\HttpResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

class HttpClientTest extends TestCase
{
    private HttpClient $httpClient;
    private array $container = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpClient = new HttpClient('https://api.example.com');
    }

    public function testConstructor(): void
    {
        $client = new HttpClient('https://example.com');
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testCreate(): void
    {
        $client = HttpClient::create('https://example.com');
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testTimeout(): void
    {
        $client = $this->httpClient->timeout(60);
        $this->assertSame($this->httpClient, $client);
    }

    public function testWithHeaders(): void
    {
        $headers = ['Content-Type' => 'application/json'];
        $client = $this->httpClient->withHeaders($headers);
        $this->assertSame($this->httpClient, $client);
    }

    public function testWithToken(): void
    {
        $client = $this->httpClient->withToken('test-token');
        $this->assertSame($this->httpClient, $client);
    }

    public function testWithBasicAuth(): void
    {
        $client = $this->httpClient->withBasicAuth('user', 'pass');
        $this->assertSame($this->httpClient, $client);
    }

    public function testRetry(): void
    {
        $client = $this->httpClient->retry(5, 2000);
        $this->assertSame($this->httpClient, $client);
    }

    public function testGetClient(): void
    {
        $client = $this->httpClient->getClient();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testSuccessfulGetRequest(): void
    {
        // Создаем mock ответ
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"status": "success"}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        // Создаем HttpClient с mock клиентом
        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $response = $httpClient->get('/test');

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertTrue($response->successful());
        $this->assertEquals(200, $response->status());
    }

    public function testPostRequestWithData(): void
    {
        // Создаем mock ответ
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], '{"id": 123}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        // Создаем HttpClient с mock клиентом
        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $data = ['name' => 'test', 'value' => 123];
        $response = $httpClient->post('/test', $data);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertEquals(201, $response->status());
    }

    public function testPutRequest(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"updated": true}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $data = ['name' => 'updated'];
        $response = $httpClient->put('/test/1', $data);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertTrue($response->successful());
    }

    public function testDeleteRequest(): void
    {
        $mock = new MockHandler([
            new Response(204)
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $response = $httpClient->delete('/test/1');

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertEquals(204, $response->status());
    }

    public function testPatchRequest(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"patched": true}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $data = ['field' => 'new_value'];
        $response = $httpClient->patch('/test/1', $data);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertTrue($response->successful());
    }

    public function testFluentApi(): void
    {
        $client = HttpClient::create('https://api.example.com')
            ->timeout(60)
            ->withHeaders(['X-Custom' => 'value'])
            ->withToken('test-token')
            ->retry(3, 1000);

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testRequestWithCustomOptions(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"custom": "response"}')
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient();
        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $client);

        $options = ['timeout' => 10, 'headers' => ['X-Test' => 'value']];
        $response = $httpClient->request('GET', '/test', $options);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertTrue($response->successful());
    }
}
