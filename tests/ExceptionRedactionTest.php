<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\RequestException;

/**
 * The previous chain of the client's exceptions (the original Guzzle exception, its handler context and
 * nested errors) used to keep the full URL, so Monolog, Sentry or an uncaught-exception dump leaked it.
 */
final class ExceptionRedactionTest extends TestCase
{
    private const PATH = '/PATH-SECRET/x?api_key=QUERY-SECRET';

    private string|false $ignoreArgs = false;

    /**
     * Stack traces show the caller's own arguments (here: the URL passed to get()) unless
     * zend.exception_ignore_args is on, as in php.ini-production. That is outside the library's control.
     */
    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '1');
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
            self::assertStringContainsString('`GET https://api.test/x?api_key=***`', $previous->getMessage());
            self::assertNoSecrets($e);
        }
    }

    public function testExceptionWithNothingToHideIsKeptIntact(): void
    {
        $original = null;
        $client = $this->client([
            static function (RequestInterface $r) use (&$original) {
                return $original = new ConnectException("cURL error 7: failed for {$r->getUri()}", $r, null, [
                    'errno' => 7,
                    'url' => (string) $r->getUri(),
                ]);
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
            self::assertNoSecrets($e);
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
     * Failure shaped like Guzzle's: URL in the message and in the handler context ("url" from curl_getinfo,
     * "error"), plus a nested PHP error as StreamHandler attaches it.
     */
    private static function leakyConnectError(): callable
    {
        return static fn(RequestInterface $r) => new ConnectException(
            "cURL error 7: Failed to connect for {$r->getUri()}",
            $r,
            new \RuntimeException("fopen({$r->getUri()}): Failed to open stream"),
            ['errno' => 7, 'url' => (string) $r->getUri(), 'error' => "Failed to connect to {$r->getUri()}"]
        );
    }

    private function assertRedactedChain(NetworkException $e): void
    {
        self::assertNoSecrets($e);

        $previous = $e->getPrevious();
        self::assertInstanceOf(ConnectException::class, $previous);
        self::assertSame('/PATH-SECRET/x', $previous->getRequest()->getUri()->getPath(), 'Request is kept intact');
        self::assertSame(7, $previous->getHandlerContext()['errno']);
        self::assertSame('https://api.test/***/x?api_key=***', $previous->getHandlerContext()['url']);

        $nested = $previous->getPrevious();
        self::assertInstanceOf(\RuntimeException::class, $nested);
        self::assertSame(
            'RuntimeException: fopen(https://api.test/***/x?api_key=***): Failed to open stream',
            $nested->getMessage()
        );
    }

    private static function assertNoSecrets(\Throwable $e): void
    {
        $dump = (string) $e;
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $dump .= $current->getMessage();
            if ($current instanceof ConnectException || $current instanceof GuzzleRequestException) {
                $dump .= var_export($current->getHandlerContext(), true);
            }
        }

        self::assertStringNotContainsString('QUERY-SECRET', $dump);
        self::assertStringNotContainsString('PATH-SECRET', $dump);
    }
}
