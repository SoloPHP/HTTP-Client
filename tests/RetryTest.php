<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\HttpClient;

/**
 * Issues #2 (POST retried by default), #3 (unbounded Retry-After), #13 (unbounded backoff);
 * review: consumed non-seekable bodies were retried empty.
 */
final class RetryTest extends TestCase
{
    public function testRetriesAreDisabledByDefault(): void
    {
        $client = $this->client([new Response(500), new Response(500), self::ok()]);

        self::assertSame(500, $client->post('/charge', ['json' => ['amount' => 100]])->status());
        self::assertSame(500, $client->get('/users')->status());
        self::assertCount(2, $this->sent);
    }

    public function testPostIsNotRetriedOn5xx(): void
    {
        $response = $this->client([new Response(500), self::ok()])->retry(3)->post('/charge');

        self::assertSame(500, $response->status());
        self::assertCount(1, $this->sent);
    }

    public function testPostIsNotRetriedAfterTimeoutBecauseServerMayHaveProcessedIt(): void
    {
        $client = $this->client([self::connectError(28), self::ok()])->retry(3);

        try {
            $client->post('/charge');
            self::fail('Expected exception');
        } catch (NetworkException) {
            self::assertCount(1, $this->sent);
        }
    }

    public function testPostIsRetriedWhenRequestWasNotSent(): void
    {
        $response = $this->client([self::connectError(7), self::ok()])->retry(3)->post('/charge');

        self::assertSame(200, $response->status());
        self::assertCount(2, $this->sent);
    }

    public function testPostIsRetriedOn429(): void
    {
        $response = $this->client([new Response(429), self::ok()])->retry(3, 1)->post('/charge');

        self::assertSame(200, $response->status());
        self::assertCount(2, $this->sent);
    }

    public function testPostIsRetriedOn5xxWhenExplicitlyAllowed(): void
    {
        $response = $this->client([new Response(500), self::ok()])
            ->retry(3, 1, retryNonIdempotent: true)
            ->post('/charge', ['headers' => ['Idempotency-Key' => 'abc']]);

        self::assertSame(200, $response->status());
        self::assertCount(2, $this->sent);
    }

    public function testIdempotentRequestIsRetriedOn5xxAndNetworkErrors(): void
    {
        $response = $this->client([new Response(503), self::connectError(28), self::ok()])->retry(3, 1)->get('/users');

        self::assertSame(200, $response->status());
        self::assertCount(3, $this->sent);
    }

    public function testMaxRetriesIsRespected(): void
    {
        $client = $this->client([new Response(500), new Response(500), new Response(500), self::ok()]);

        self::assertSame(500, $client->retry(2, 1)->get('/users')->status());
        self::assertCount(3, $this->sent);
    }

    public function testHugeRetryAfterIsCappedByMaxDelay(): void
    {
        self::assertSame(30000, $this->delayAfter('99999999'));
        self::assertSame(500, $this->delayAfter('99999999', maxDelayMs: 500));
    }

    public function testInvalidRetryAfterFallsBackToBackoff(): void
    {
        foreach (['-1', 'garbage', '1.5'] as $value) {
            $delay = $this->delayAfter($value, delayMs: 100);
            self::assertIsInt($delay);
            self::assertGreaterThanOrEqual(100, $delay, "Retry-After: {$value}");
            self::assertLessThanOrEqual(110, $delay, "Retry-After: {$value}");
        }
    }

    public function testHttpDateRetryAfterIsSupported(): void
    {
        $delay = $this->delayAfter(gmdate('D, d M Y H:i:s \G\M\T', time() + 20));
        self::assertIsInt($delay);
        self::assertGreaterThanOrEqual(18000, $delay);
        self::assertLessThanOrEqual(20000, $delay);

        self::assertSame(0, $this->delayAfter(gmdate('D, d M Y H:i:s \G\M\T', time() - 3600)));
    }

    public function testBackoffIsExponentialAndCapped(): void
    {
        $queue = array_merge(array_fill(0, 5, new Response(500)), [self::ok()]);
        $this->client($queue)->retry(5, 100, 300)->get('/');

        $delays = array_map(static fn(array $sent): mixed => $sent['options']['delay'], array_slice($this->sent, 1));
        self::assertCount(5, $delays);
        self::assertGreaterThanOrEqual(100, $delays[0]);
        self::assertLessThanOrEqual(110, $delays[0]);
        self::assertGreaterThanOrEqual(200, $delays[1]);
        self::assertLessThanOrEqual(220, $delays[1]);
        self::assertSame([300, 300, 300], array_slice($delays, 2));
    }

    public function testNonSeekableBodyIsNotRetried(): void
    {
        $bodies = [];
        $client = $this->consumingClient([new Response(503), self::ok()], $bodies)->retry(3, 1);

        $response = $client->put('/x', ['body' => new NoSeekStream(Utils::streamFor('PAYLOAD'))]);

        self::assertSame(503, $response->status());
        self::assertSame(['PAYLOAD'], $bodies);
    }

    public function testSeekableBodyIsRetriedIntact(): void
    {
        $bodies = [];
        $client = $this->consumingClient([new Response(503), self::ok()], $bodies)->retry(3, 1);

        $response = $client->put('/x', ['body' => 'PAYLOAD']);

        self::assertSame(200, $response->status());
        self::assertSame(['PAYLOAD', 'PAYLOAD'], $bodies);
    }

    public function testNegativeParametersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->retry(-1);
    }

    /**
     * Delay chosen for the single retry after a 503 with the given Retry-After header.
     */
    private function delayAfter(string $retryAfter, int $delayMs = 1000, int $maxDelayMs = 30000): mixed
    {
        $this->sent = [];
        $this->client([new Response(503, ['Retry-After' => $retryAfter]), self::ok()])
            ->retry(1, $delayMs, $maxDelayMs)
            ->get('/');

        return $this->sent[1]['options']['delay'];
    }

    /**
     * Records the body of each attempt as a transport sees it: seekable bodies are rewound first
     * (as cURL does), a non-seekable one can be read only once.
     *
     * @param list<mixed> $queue
     * @param list<string> $bodies
     */
    private function consumingClient(array $queue, array &$bodies): HttpClient
    {
        $recording = $this->recordingHandler($queue);

        return new HttpClient('https://api.test', [
            'handler' => static function (RequestInterface $request, array $options) use ($recording, &$bodies) {
                $body = $request->getBody();
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                $bodies[] = $body->getContents();
                return $recording($request, $options);
            },
        ]);
    }
}
