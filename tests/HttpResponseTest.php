<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use Solo\HttpClient\HttpResponse;

/**
 * Issue #5: json() returned a truthy Response object on invalid JSON (fail-open) and threw TypeError on scalars.
 * Review: body() kept a second full copy of seekable bodies in memory.
 */
final class HttpResponseTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonBodies(): iterable
    {
        yield 'HTML error page from proxy' => ['<html><body>Bad Gateway</body></html>'];
        yield 'empty body (204)' => [''];
        yield 'truncated JSON' => ['{"allowed": tr'];
    }

    #[DataProvider('invalidJsonBodies')]
    public function testInvalidJsonFailsClosed(string $body): void
    {
        $response = new HttpResponse(new Response(502, [], $body));

        self::assertNull($response->json());
        self::assertNull($response->json('allowed'));
        self::assertFalse((bool) $response->json('allowed'));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function scalarJsonBodies(): iterable
    {
        yield 'true' => ['true', true];
        yield 'string' => ['"ok"', 'ok'];
        yield 'int' => ['42', 42];
        yield 'null' => ['null', null];
    }

    #[DataProvider('scalarJsonBodies')]
    public function testScalarJsonIsReturnedWithoutTypeError(string $body, mixed $expected): void
    {
        $response = new HttpResponse(new Response(200, [], $body));

        self::assertSame($expected, $response->json());
        self::assertNull($response->json('key'));
    }

    public function testNestedKeyLookup(): void
    {
        $response = new HttpResponse(new Response(200, [], '{"user":{"profile":{"email":"a@b.c"}},"list":[1,2]}'));

        self::assertSame('a@b.c', $response->json('user.profile.email'));
        self::assertSame(2, $response->json('list.1'));
        self::assertNull($response->json('user.missing'));
    }

    public function testSeekableBodyIsNotCachedAsString(): void
    {
        $response = new HttpResponse(new Response(200, [], '{"a":1}'));

        self::assertSame('{"a":1}', $response->body());
        self::assertSame('{"a":1}', $response->body());
        self::assertSame(1, $response->json('a'));
        self::assertNull((fn() => $this->body)->call($response));
    }

    public function testBodyIsCachedForNonSeekableStreams(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('{"a":1}'));
        $response = new HttpResponse(new Response(200, [], $stream));

        self::assertSame('{"a":1}', $response->body());
        self::assertSame('{"a":1}', $response->body());
        self::assertSame(1, $response->json('a'));
    }
}
