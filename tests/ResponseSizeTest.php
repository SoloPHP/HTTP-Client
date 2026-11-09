<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Solo\HttpClient\Exception\ResponseTooLargeException;
use Solo\HttpClient\HttpResponse;

/**
 * withMaxResponseSize(): a response larger than the limit is refused by its Content-Length, or once its body goes
 * past the limit, wherever the body goes; the refusal is not retried.
 */
final class ResponseSizeTest extends TestCase
{
    private const string BODY = '0123456789';

    public function testBodyWithinTheLimitIsReturned(): void
    {
        $response = $this->client([new Response(200, ['Content-Length' => '10'], self::BODY)])
            ->withMaxResponseSize(10)
            ->get('/file');

        self::assertSame(self::BODY, $response->body());
    }

    public function testAnnouncedLengthOverTheLimitIsRefused(): void
    {
        $client = $this->client([new Response(200, ['Content-Length' => '11'], self::BODY . 'x')])
            ->withMaxResponseSize(10);

        $e = $this->refusal(static fn() => $client->get('/file'));

        self::assertSame(10, $e->getLimit());
        self::assertSame('HTTP request failed: the response body exceeds the limit of 10 bytes', $e->getMessage());
        self::assertSame('/file', $e->getRequest()->getUri()->getPath());
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
    }

    public function testLengthPastTheIntegerRangeIsRefused(): void
    {
        $client = $this->client([new Response(200, ['Content-Length' => '99999999999999999999999'], self::BODY)])
            ->withMaxResponseSize(PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, $this->refusal(static fn() => $client->get('/file'))->getLimit());
    }

    public function testBodyGoingPastTheLimitIsRefused(): void
    {
        // No Content-Length: the body is counted as it reaches the sink.
        $client = $this->client([new Response(200, [], self::BODY . 'x')])->withMaxResponseSize(10);

        self::assertSame(10, $this->refusal(static fn() => $client->get('/file'))->getLimit());
    }

    public function testLengthOfAResponseWithoutBodyIsIgnored(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Length' => '1000']),
            new Response(304, ['Content-Length' => '1000']),
        ])->withMaxResponseSize(10);

        self::assertSame(200, $client->head('/file')->status());
        self::assertSame(304, $client->get('/file')->status());
    }

    public function testRefusalIsNotRetried(): void
    {
        $client = $this->client([new Response(200, [], self::BODY . 'x'), self::ok()])
            ->withMaxResponseSize(10)
            ->retry(3);

        $this->refusal(static fn() => $client->get('/file'));
        self::assertCount(1, $this->sent);
    }

    public function testSinkPathGetsTheBodyAndNeverMoreThanTheLimit(): void
    {
        $path = $this->tempFile('');
        $client = $this->client([new Response(200, [], self::BODY), new Response(200, [], self::BODY . 'x')])
            ->withMaxResponseSize(10);

        $client->get('/file', ['sink' => $path]);
        self::assertSame(self::BODY, file_get_contents($path));

        $this->refusal(static fn() => $client->get('/file', ['sink' => $path]));
        self::assertLessThanOrEqual(10, filesize($path));
    }

    public function testResourceSinkStaysTheCallersToClose(): void
    {
        $sink = Utils::tryFopen('php://temp', 'w+');

        $response = $this->client([new Response(200, [], self::BODY)])
            ->withMaxResponseSize(10)
            ->get('/file', ['sink' => $sink]);
        $response->toPsrResponse()->getBody()->close();
        unset($response);

        self::assertIsResource($sink);
        rewind($sink);
        self::assertSame(self::BODY, stream_get_contents($sink));
        fclose($sink);
    }

    public function testOwnOnHeadersStillRuns(): void
    {
        $status = null;
        $this->client([new Response(200, [], self::BODY)])
            ->withMaxResponseSize(10)
            ->get('/file', ['on_headers' => static function (ResponseInterface $response) use (&$status): void {
                $status = $response->getStatusCode();
            }]);

        self::assertSame(200, $status);
    }

    public function testStreamedBodyIsCountedAsItIsRead(): void
    {
        $body = $this->streamed(self::BODY . 'x')->toPsrResponse()->getBody();

        self::assertSame(self::BODY, $body->read(10));
        $this->expectException(ResponseTooLargeException::class);
        $body->read(10);
    }

    public function testSeekableStreamedBodyCanBeReadAgainWithinTheLimit(): void
    {
        $response = $this->streamed(self::BODY);

        self::assertSame(self::BODY, $response->body());
        self::assertSame(self::BODY, $response->body());
    }

    public function testSeekingInAStreamedBodyCountsItsPosition(): void
    {
        $body = $this->streamed(self::BODY . 'x')->toPsrResponse()->getBody();

        $body->seek(9);
        self::assertSame('9', $body->read(1));
        $this->expectException(ResponseTooLargeException::class);
        $body->read(1);
    }

    public function testStreamedBodyIsCountedFromItsPositionBeforeAndAfterASeek(): void
    {
        $source = Utils::streamFor(self::BODY . 'x');
        $source->seek(5);
        $body = $this->streamed($source)->toPsrResponse()->getBody();

        self::assertSame('56789', $body->read(5));
        $this->refusal(static fn() => $body->read(1));

        $body->seek(5);
        self::assertSame('56789', $body->read(5));
        self::assertSame(10, $this->refusal(static fn() => $body->read(1))->getLimit());
    }

    public function testStreamedReadIsBoundedBeforeTheUnderlyingStreamAllocates(): void
    {
        $requested = null;
        $source = Utils::streamFor(self::BODY . 'x');
        $stream = FnStream::decorate($source, [
            'read' => static function (int $length) use ($source, &$requested): string {
                $requested = $length;
                return $source->read(min($length, 11));
            },
        ]);
        $body = $this->streamed($stream)->toPsrResponse()->getBody();

        self::assertSame(10, $this->refusal(static fn() => $body->read(PHP_INT_MAX))->getLimit());
        self::assertSame(11, $requested);
    }

    public function testAsynchronousRequestIsRejected(): void
    {
        $promise = $this->client([new Response(200, [], self::BODY . 'x')])
            ->withMaxResponseSize(10)
            ->getAsync('/file');

        self::assertSame(10, $this->refusal(static fn() => $promise->wait())->getLimit());
    }

    public function testClientItWasDerivedFromStaysUnlimited(): void
    {
        $client = $this->client([new Response(200, [], self::BODY . 'x')]);
        $client->withMaxResponseSize(10);

        self::assertSame(self::BODY . 'x', $client->get('/file')->body());
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->withMaxResponseSize(-1);
    }

    /**
     * A response with the stream option from a client limited to 10 bytes.
     */
    private function streamed(string|StreamInterface $body): HttpResponse
    {
        return $this->client([new Response(200, [], $body)])
            ->withMaxResponseSize(10)
            ->get('/file', ['stream' => true]);
    }

    private function refusal(callable $send): ResponseTooLargeException
    {
        try {
            $send();
        } catch (ResponseTooLargeException $e) {
            return $e;
        }

        self::fail('The response was not refused');
    }
}
