<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\HttpClient;

/**
 * Issue #9: a custom "handler" was silently replaced, so MockHandler and custom middleware did not work.
 * Review: every clone created its own default handler, losing connection reuse.
 */
final class HandlerTest extends TestCase
{
    public function testCustomHandlerIsHonored(): void
    {
        $client = new HttpClient('https://api.test', ['handler' => new MockHandler([self::ok('FROM-MOCK')])]);

        self::assertSame('FROM-MOCK', $client->get('/')->body());
    }

    public function testCustomHandlerStackIsHonoredAndNotMutated(): void
    {
        $stack = HandlerStack::create($this->recordingHandler([self::ok()]));
        $before = (string) $stack;

        (new HttpClient('https://api.test', ['handler' => $stack]))
            ->retry(1)
            ->withToken('t')
            ->withLogging(static function (string $m): void {
            })
            ->get('/');

        self::assertCount(1, $this->sent);
        self::assertSame($before, (string) $stack);
    }

    public function testWithMiddlewareIsApplied(): void
    {
        $this->client([self::ok()])
            ->withMiddleware(Middleware::mapRequest(
                static fn(RequestInterface $r) => $r->withHeader('X-Signed', 'yes')
            ), 'signer')
            ->get('/');

        self::assertSame('yes', $this->sentRequest()->getHeaderLine('X-Signed'));
    }

    public function testDefaultHandlerIsSharedAcrossClones(): void
    {
        $original = HttpClient::create('https://api.test');
        $clones = [$original->withToken('a'), $original->timeout(3), $original->retry()->withHeaders(['X' => '1'])];

        $handlerOf = static fn(HttpClient $client): mixed => (fn() => $this->handler)->call($client);

        self::assertIsCallable($handlerOf($original));
        foreach ($clones as $clone) {
            self::assertSame($handlerOf($original), $handlerOf($clone));
        }
    }

    public function testNonCallableHandlerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpClient('', ['handler' => 'not-a-function']);
    }
}
