<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\NetworkTimeoutException as ClientNetworkTimeoutException;
use Solo\HttpClient\Exception\RequestException;
use Solo\HttpClient\Exception\ResponseTimeoutException as ClientResponseTimeoutException;
use Solo\HttpClient\Exception\TimeoutException;

/**
 * The previous chain of the client's exceptions (the original Guzzle exception and nested errors) used to
 * keep the full URL, so Monolog, Sentry or an uncaught-exception dump leaked it. Stack traces are checked
 * too: zend.exception_ignore_args stays off, as in development, and the client's frames must not show
 * the URL its caller passed.
 */
final class ExceptionRedactionTest extends TestCase
{
    private const PATH = '/PATH-SECRET/x?api_key=QUERY-SECRET';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
        parent::tearDown();
    }

    public function testSyncPreviousChainIsRedacted(): void
    {
        try {
            $this->client([self::leakyConnectError()])->withRedaction('PATH-SECRET')->get(self::PATH);
            self::fail('Expected exception');
        } catch (NetworkException $e) {
            $this->assertRedactedChain($e);
        }
    }

    public function testAsyncPreviousChainIsRedacted(): void
    {
        try {
            $this->client([self::leakyConnectError()])->withRedaction('PATH-SECRET')->getAsync(self::PATH)->wait();
            self::fail('Expected exception');
        } catch (NetworkException $e) {
            $this->assertRedactedChain($e);
        }
    }

    public function testServerExceptionKeepsClassAndResponse(): void
    {
        $client = $this->client([new Response(500, [], 'boom')], config: ['http_errors' => true]);

        try {
            $client->get('/x?api_key=QUERY-SECRET');
            self::fail('Expected exception');
        } catch (RequestException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(ServerException::class, $previous);
            self::assertSame(500, $previous->getResponse()->getStatusCode());
            self::assertStringContainsString('`GET https://api.test/x`', $previous->getMessage());
            self::assertNoSecrets($e);
        }
    }

    /**
     * @return iterable<string, array{callable(RequestInterface): TransferException, class-string}>
     */
    public static function transferExceptions(): iterable
    {
        $message = static fn(RequestInterface $r): string => "Transfer failed for {$r->getUri()}";

        yield 'connect timeout' => [
            static fn(RequestInterface $r) => new ConnectTimeoutException($message($r), $r),
            ClientNetworkTimeoutException::class,
        ];
        yield 'network timeout' => [
            static fn(RequestInterface $r) => new NetworkTimeoutException($message($r), $r),
            ClientNetworkTimeoutException::class,
        ];
        yield 'response timeout' => [
            static fn(RequestInterface $r) => new ResponseTimeoutException($message($r), $r, new Response(200)),
            ClientResponseTimeoutException::class,
        ];
        yield 'connect failure' => [
            static fn(RequestInterface $r) => new ConnectException($message($r), $r),
            NetworkException::class,
        ];
        yield 'request failure' => [
            static fn(RequestInterface $r) => new GuzzleRequestException($message($r), $r, 3),
            RequestException::class,
        ];
        yield 'closed handler' => [
            static fn(RequestInterface $r) => new HandlerClosedException($message($r), $r),
            HttpClientException::class,
        ];
    }

    /**
     * @param callable(RequestInterface): TransferException $failure
     * @param class-string $clientClass
     */
    #[DataProvider('transferExceptions')]
    public function testEveryTransferExceptionKeepsItsClass(callable $failure, string $clientClass): void
    {
        $original = null;
        $client = $this->client([
            static function (RequestInterface $r) use ($failure, &$original) {
                return $original = $failure($r);
            },
        ]);

        try {
            $client->get('/x?api_key=QUERY-SECRET');
            self::fail('Expected exception');
        } catch (HttpClientException $e) {
            self::assertSame($clientClass, $e::class);
            self::assertInstanceOf(TransferException::class, $original);
            $previous = $e->getPrevious();
            self::assertInstanceOf($original::class, $previous);
            self::assertSame($original->getCode(), $previous->getCode());
            self::assertSame($original->getRequest(), $previous->getRequest());
            if ($original instanceof ResponseTimeoutException) {
                self::assertInstanceOf(ResponseTimeoutException::class, $previous);
                self::assertSame($original->getResponse(), $previous->getResponse());
            }
            self::assertNoSecrets($e);
        }
    }

    /**
     * One catch for a timeout before the response and after its headers; each stays in its PSR-18 branch.
     */
    public function testTimeoutsShareTimeoutException(): void
    {
        $timeouts = [
            ClientNetworkTimeoutException::class
                => static fn(RequestInterface $r) => new ConnectTimeoutException('t', $r),
            ClientResponseTimeoutException::class
                => static fn(RequestInterface $r) => new ResponseTimeoutException('t', $r, new Response(200)),
        ];

        foreach ($timeouts as $class => $failure) {
            try {
                $this->client([$failure])->get('/slow');
                self::fail('Expected exception');
            } catch (TimeoutException $e) {
                self::assertInstanceOf($class, $e);
            }
        }

        self::assertTrue(is_subclass_of(ClientNetworkTimeoutException::class, NetworkException::class));
        self::assertTrue(is_subclass_of(ClientResponseTimeoutException::class, RequestException::class));
    }

    public function testUrlThatDoesNotParseIsAFailedRequest(): void
    {
        $client = $this->client([self::ok()])->withRedaction('PATH-SECRET');

        try {
            $client->get('http://exa mple.test/PATH-SECRET/x');
            self::fail('Expected exception');
        } catch (HttpClientException $e) {
            self::assertSame(HttpClientException::class, $e::class);
            self::assertSame('HTTP request failed: Invalid host: exa mple.test', $e->getMessage());
            self::assertInstanceOf(MalformedUriException::class, $e->getPrevious());
        }

        try {
            $client->getAsync('http:///PATH-SECRET/x')->wait();
            self::fail('Expected exception');
        } catch (HttpClientException $e) {
            self::assertSame(HttpClientException::class, $e::class);
            self::assertNoSecrets($e);
        }

        self::assertSame([], $this->sent);
    }

    public function testExceptionWithNothingToHideIsKeptIntact(): void
    {
        $original = null;
        $client = $this->client([
            static function (RequestInterface $r) use (&$original) {
                return $original = new ConnectException("cURL error 7: failed for {$r->getUri()}", $r);
            },
        ]);

        try {
            $client->get('/plain/path');
            self::fail('Expected exception');
        } catch (NetworkException $e) {
            self::assertSame($original, $e->getPrevious());
        }
    }

    public function testGuzzleClientFromGetClientIsCoveredToo(): void
    {
        try {
            $this->client([self::leakyConnectError()])
                ->withRedaction('PATH-SECRET')
                ->getClient()
                ->request('GET', self::PATH);
            self::fail('Expected exception');
        } catch (ConnectException $e) {
            self::assertNoSecrets($e, withTrace: false);
        }
    }

    public function testUnexpectedConstructorKeepsOriginalButClientMessageIsRedacted(): void
    {
        $original = null;
        $client = $this->client([
            static function (RequestInterface $r) use (&$original) {
                return $original = new class ($r) extends GuzzleRequestException {
                    public function __construct(RequestInterface $request)
                    {
                        parent::__construct("Odd failure for {$request->getUri()}", $request);
                    }
                };
            },
        ]);

        try {
            $client->get('/x?api_key=QUERY-SECRET');
            self::fail('Expected exception');
        } catch (RequestException $e) {
            self::assertSame($original, $e->getPrevious());
            self::assertStringNotContainsString('QUERY-SECRET', $e->getMessage());
        }
    }

    /**
     * Failure shaped like Guzzle's: URL in the message, plus a nested PHP error as StreamHandler attaches it.
     */
    private static function leakyConnectError(): callable
    {
        return static fn(RequestInterface $r) => new ConnectException(
            "cURL error 7: Failed to connect for {$r->getUri()}",
            $r,
            new \RuntimeException("fopen({$r->getUri()}): Failed to open stream")
        );
    }

    private function assertRedactedChain(NetworkException $e): void
    {
        self::assertNoSecrets($e);

        $previous = $e->getPrevious();
        self::assertInstanceOf(ConnectException::class, $previous);
        self::assertSame('/PATH-SECRET/x', $previous->getRequest()->getUri()->getPath(), 'Request is kept intact');
        self::assertSame(
            'cURL error 7: Failed to connect for https://api.test/***/x?api_key=***',
            $previous->getMessage()
        );

        $nested = $previous->getPrevious();
        self::assertInstanceOf(\RuntimeException::class, $nested);
        self::assertSame(
            'RuntimeException: fopen(https://api.test/***/x?api_key=***): Failed to open stream',
            $nested->getMessage()
        );
    }

    /**
     * Messages of the whole chain and, by default, the client's own stack trace with its arguments.
     */
    private static function assertNoSecrets(\Throwable $e, bool $withTrace = true): void
    {
        $dump = $withTrace ? self::traceArguments($e) : '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $dump .= $current->getMessage();
        }

        self::assertStringNotContainsString('QUERY-SECRET', $dump);
        self::assertStringNotContainsString('PATH-SECRET', $dump);
    }
}
