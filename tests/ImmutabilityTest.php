<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Middleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Solo\HttpClient\HttpClient;

/**
 * Issue #4: with*() mutated a shared instance, leaking one user's token into another user's requests.
 */
final class ImmutabilityTest extends TestCase
{
    public function testTokenOfOneCallerDoesNotLeakIntoSharedInstance(): void
    {
        $shared = $this->client([self::ok(), self::ok()]); // e.g. a DI singleton

        $shared->withToken('token-of-user-A')->get('/me');
        $shared->get('/me');

        self::assertSame('Bearer token-of-user-A', $this->sentRequest(0)->getHeaderLine('Authorization'));
        self::assertFalse($this->sentRequest(1)->hasHeader('Authorization'));
    }

    /**
     * @return iterable<string, array{callable(HttpClient): HttpClient}>
     */
    public static function fluentCalls(): iterable
    {
        yield 'timeout' => [static fn(HttpClient $c) => $c->timeout(5)];
        yield 'withHeaders' => [static fn(HttpClient $c) => $c->withHeaders(['X-A' => '1'])];
        yield 'withToken' => [static fn(HttpClient $c) => $c->withToken('t')];
        yield 'withBasicAuth' => [static fn(HttpClient $c) => $c->withBasicAuth('u', 'p')];
        yield 'retry' => [static fn(HttpClient $c) => $c->retry()];
        yield 'withLogging' => [static fn(HttpClient $c) => $c->withLogging(static function (string $m): void {
        })];
        yield 'withMiddleware' => [static fn(HttpClient $c) => $c->withMiddleware(Middleware::mapRequest(
            static fn($r) => $r
        ))];
    }

    /**
     * @param callable(HttpClient): HttpClient $call
     */
    #[DataProvider('fluentCalls')]
    public function testFluentMethodsReturnNewInstanceAndKeepOriginalUntouched(callable $call): void
    {
        $original = $this->client([self::ok(), self::ok()]);
        $clientBefore = $original->getClient();

        $modified = $call($original);

        self::assertNotSame($original, $modified);
        self::assertSame($clientBefore, $original->getClient());
    }

    public function testTimeoutAppliesOnlyToTheNewInstance(): void
    {
        $original = $this->client([self::ok(), self::ok()]);

        $original->timeout(5)->get('/');
        $original->get('/');

        self::assertSame(5, $this->sent[0]['options']['timeout']);
        self::assertSame(30, $this->sent[1]['options']['timeout']);
    }

    public function testHeadersAreMergedCaseInsensitively(): void
    {
        $this->client([self::ok()])
            ->withHeaders(['X-Custom' => 'old'])
            ->withHeaders(['x-custom' => 'new'])
            ->get('/');

        self::assertSame(['new'], $this->sentRequest()->getHeader('X-Custom'));
    }

    public function testClonesBuildTheirGuzzleClientOnFirstUse(): void
    {
        $clientOf = static fn(HttpClient $client): mixed => (fn() => $this->client)->call($client);

        $configured = HttpClient::create('https://api.test')->withToken('t')->timeout(3)->retry();

        self::assertNull($clientOf($configured));
        self::assertSame($configured->getClient(), $clientOf($configured));
    }
}
