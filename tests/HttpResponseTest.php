<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Solo\HttpClient\HttpResponse;
use GuzzleHttp\Psr7\Response;

class HttpResponseTest extends TestCase
{
    public function testConstructor(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertInstanceOf(HttpResponse::class, $response);
    }

    public function testStatus(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals(200, $response->status());
    }

    public function testSuccessful(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->successful());
    }

    public function testSuccessfulWith201(): void
    {
        $psrResponse = new Response(201);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->successful());
    }

    public function testNotSuccessfulWith400(): void
    {
        $psrResponse = new Response(400);
        $response = new HttpResponse($psrResponse);

        $this->assertFalse($response->successful());
    }

    public function testNotSuccessfulWith500(): void
    {
        $psrResponse = new Response(500);
        $response = new HttpResponse($psrResponse);

        $this->assertFalse($response->successful());
    }

    public function testOk(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->ok());
    }

    public function testNotOk(): void
    {
        $psrResponse = new Response(201);
        $response = new HttpResponse($psrResponse);

        $this->assertFalse($response->ok());
    }

    public function testClientError(): void
    {
        $psrResponse = new Response(400);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->clientError());
    }

    public function testClientErrorWith404(): void
    {
        $psrResponse = new Response(404);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->clientError());
    }

    public function testNotClientError(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertFalse($response->clientError());
    }

    public function testServerError(): void
    {
        $psrResponse = new Response(500);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->serverError());
    }

    public function testServerErrorWith503(): void
    {
        $psrResponse = new Response(503);
        $response = new HttpResponse($psrResponse);

        $this->assertTrue($response->serverError());
    }

    public function testNotServerError(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertFalse($response->serverError());
    }

    public function testBody(): void
    {
        $body = '{"status": "success"}';
        $psrResponse = new Response(200, [], $body);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals($body, $response->body());
    }

    public function testJson(): void
    {
        $jsonData = '{"status": "success", "data": {"id": 123}}';
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonData);
        $response = new HttpResponse($psrResponse);

        $result = $response->json();
        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(123, $result['data']['id']);
    }

    public function testJsonWithKey(): void
    {
        $jsonData = '{"status": "success", "data": {"id": 123, "name": "test"}}';
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonData);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals('success', $response->json('status'));
        $this->assertEquals(123, $response->json('data.id'));
        $this->assertEquals('test', $response->json('data.name'));
    }

    public function testJsonWithNestedKey(): void
    {
        $jsonData = '{"user": {"profile": {"name": "John", "age": 30}}}';
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonData);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals('John', $response->json('user.profile.name'));
        $this->assertEquals(30, $response->json('user.profile.age'));
    }

    public function testJsonWithInvalidKey(): void
    {
        $jsonData = '{"status": "success"}';
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonData);
        $response = new HttpResponse($psrResponse);

        $this->assertNull($response->json('nonexistent'));
        $this->assertNull($response->json('status.nonexistent'));
    }

    public function testJsonWithInvalidJson(): void
    {
        $invalidJson = '{"status": "success"'; // Неполный JSON
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $invalidJson);
        $response = new HttpResponse($psrResponse);

        $result = $response->json();
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
    }

    public function testHeader(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Custom' => 'value'];
        $psrResponse = new Response(200, $headers);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals('application/json', $response->header('Content-Type'));
        $this->assertEquals('value', $response->header('X-Custom'));
    }

    public function testHeaderNotFound(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $this->assertNull($response->header('Nonexistent-Header'));
    }

    public function testHeaders(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Custom' => 'value'];
        $psrResponse = new Response(200, $headers);
        $response = new HttpResponse($psrResponse);

        $result = $response->headers();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('Content-Type', $result);
        $this->assertArrayHasKey('X-Custom', $result);
    }

    public function testToPsrResponse(): void
    {
        $psrResponse = new Response(200);
        $response = new HttpResponse($psrResponse);

        $result = $response->toPsrResponse();
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame($psrResponse, $result);
    }

    public function testToString(): void
    {
        $body = '{"status": "success"}';
        $psrResponse = new Response(200, [], $body);
        $response = new HttpResponse($psrResponse);

        $this->assertEquals($body, (string) $response);
    }

    public function testJsonCaching(): void
    {
        $jsonData = '{"status": "success"}';
        $psrResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonData);
        $response = new HttpResponse($psrResponse);

        // Первый вызов должен распарсить JSON
        $result1 = $response->json();
        $this->assertIsArray($result1);

        // Второй вызов должен использовать кэш
        $result2 = $response->json();
        $this->assertIsArray($result2);
        $this->assertEquals($result1, $result2);
    }
}
