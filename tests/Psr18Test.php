<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\RequestAwareException;
use Solo\HttpClient\Exception\RequestException;
use Solo\HttpClient\HttpResponse;

/**
 * Issue #10 (PSR-18 claimed but not implemented) and #12 (async errors not wrapped, __call reaching
 * private Guzzle methods).
 */
final class Psr18Test extends TestCase
{
    public function testImplementsPsr18(): void
    {
        self::assertInstanceOf(ClientInterface::class, $this->client([]));
    }

    public function testSendRequestReturnsPsrResponseForErrorStatus(): void
    {
        $response = $this->client([new Response(500)])->sendRequest(new Request('GET', 'https://api.test/'));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    public function testNetworkErrorIsPsr18AndRuntimeException(): void
    {
        $request = new Request('GET', 'https://api.test/');

        try {
            $this->client([self::connectError(7)])->sendRequest($request);
            self::fail('Expected exception');
        } catch (NetworkExceptionInterface $e) {
            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertSame('api.test', $e->getRequest()->getUri()->getHost());
        }
    }

    public function testGuzzleRequestExceptionIsWrappedAsPsr18RequestException(): void
    {
        $client = $this->client([
            static fn(RequestInterface $r) => new GuzzleRequestException("Transfer failed for {$r->getUri()}", $r),
        ]);

        try {
            $client->get('/x?api_key=SECRET');
            self::fail('Expected exception');
        } catch (RequestException $e) {
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertInstanceOf(RequestAwareException::class, $e);
            self::assertSame('/x', $e->getRequest()->getUri()->getPath());
            self::assertStringContainsString('https://api.test/x?api_key=***', $e->getMessage());
        }
    }

    public function testAsyncErrorsAreWrappedLikeSyncOnes(): void
    {
        $this->expectException(NetworkException::class);
        $this->client([self::connectError(7)])->getAsync('/')->wait();
    }

    public function testAsyncResponsesAreWrapped(): void
    {
        $client = $this->client([self::ok('a'), self::ok('b')]);

        $first = $client->putAsync('/')->wait();
        $second = $client->deleteAsync('/')->wait();

        self::assertInstanceOf(HttpResponse::class, $first);
        self::assertInstanceOf(HttpResponse::class, $second);
        self::assertSame(['PUT', 'DELETE'], [$this->sentRequest(0)->getMethod(), $this->sentRequest(1)->getMethod()]);
    }

    public function testMagicCallDoesNotReachPrivateOrUnknownMethods(): void
    {
        $client = $this->client([self::ok(), self::ok()]);

        foreach (['transfer', 'applyOptions', 'options', 'foo'] as $method) {
            try {
                $client->{$method}('/x');
                self::fail("Expected BadMethodCallException for {$method}");
            } catch (\BadMethodCallException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertCount(0, $this->sent, 'No request must be sent with a bogus HTTP method');
    }

    public function testHeadAndSendWork(): void
    {
        $client = $this->client([self::ok(), self::ok('sent')]);

        self::assertSame(200, $client->head('/')->status());
        self::assertSame('sent', $client->send(new Request('POST', '/send'))->body());
        self::assertSame('HEAD', $this->sentRequest(0)->getMethod());
    }

    public function testSynchronousGuzzleErrorsAreWrappedOnAsyncPathToo(): void
    {
        // Guzzle throws this from applyOptions() before any promise exists.
        $options = ['form_params' => ['a' => '1'], 'multipart' => [['name' => 'b', 'contents' => '2']]];
        $client = $this->client([]);

        foreach ([fn() => $client->post('/', $options), fn() => $client->postAsync('/', $options)->wait()] as $call) {
            try {
                $call();
                self::fail('Expected exception');
            } catch (HttpClientException $e) {
                self::assertStringContainsString('form_params', $e->getMessage());
            }
        }
    }

    public function testGetConfigIsAvailable(): void
    {
        self::assertSame(30, $this->client([])->getConfig('timeout'));
        self::assertSame(5, $this->client([])->timeout(5)->getConfig('timeout'));
    }
}
