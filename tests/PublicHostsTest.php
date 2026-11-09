<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\RequestExceptionInterface;
use Solo\HttpClient\Exception\BlockedHostException;
use Solo\HttpClient\Exception\HttpClientException;
use Solo\HttpClient\Exception\NetworkException;
use Solo\HttpClient\Exception\RequestException;
use Solo\HttpClient\HttpClient;

/**
 * withPublicHostsOnly(): URLs from users reach public addresses only, on every redirect hop and every retry,
 * and a second DNS answer cannot swap in another address after the check (DNS rebinding).
 */
final class PublicHostsTest extends TestCase
{
    private const string PUBLIC_V4 = '93.184.216.34';
    private const string PUBLIC_V6 = '2606:2800:220:1:248:1893:25c8:1946';

    /** @var list<string> Host names the resolver was asked for */
    private array $lookups = [];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function publicLiterals(): iterable
    {
        yield 'IPv4' => ['http://93.184.216.34/', '::93.184.216.34:'];
        yield 'IPv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/', '::[2606:2800:220:1:248:1893:25c8:1946]:'];
        yield 'IPv4-mapped IPv6, pinned as IPv4' => ['http://[::ffff:93.184.216.34]/', '::93.184.216.34:'];
        yield 'NAT64 of a public IPv4' => ['http://[64:ff9b::5db8:d822]/', '::[64:ff9b::5db8:d822]:'];
        yield 'decimal IPv4' => ['http://1572395042:8080/', '::93.184.216.34:'];
    }

    #[DataProvider('publicLiterals')]
    public function testPublicAddressIsPinned(string $url, string $connectTo): void
    {
        $this->guarded([self::ok()])->get($url);

        self::assertSame([$connectTo], $this->connectTo());
        self::assertSame([], $this->lookups);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonPublicLiterals(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/', '127.0.0.1'];
        yield 'loopback range' => ['http://127.255.0.1/', '127.255.0.1'];
        yield 'this network' => ['http://0.0.0.0:8080/', '0.0.0.0'];
        yield 'private 10/8' => ['http://10.1.2.3/', '10.1.2.3'];
        yield 'private 172.16/12' => ['http://172.31.255.255/', '172.31.255.255'];
        yield 'private 192.168/16' => ['http://192.168.0.1/', '192.168.0.1'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'];
        yield 'carrier-grade NAT' => ['http://100.64.0.1/', '100.64.0.1'];
        yield 'IETF protocol assignments' => ['http://192.0.0.8/', '192.0.0.8'];
        yield 'benchmarking' => ['http://198.18.0.1/', '198.18.0.1'];
        yield 'documentation' => ['http://203.0.113.7/', '203.0.113.7'];
        yield 'multicast' => ['http://224.0.0.1/', '224.0.0.1'];
        yield 'broadcast' => ['http://255.255.255.255/', '255.255.255.255'];
        yield 'IPv6 loopback' => ['http://[::1]/', '::1'];
        yield 'IPv6 loopback, long form' => ['http://[0:0:0:0:0:0:0:1]/', '::1'];
        yield 'IPv6 unspecified' => ['http://[::]/', '::'];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/', '127.0.0.1'];
        yield 'IPv4-mapped metadata in hex' => ['http://[::ffff:a9fe:a9fe]/', '169.254.169.254'];
        yield 'IPv4-compatible loopback' => ['http://[::127.0.0.1]/', '::7f00:1'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/', 'fe80::1'];
        yield 'IPv6 unique local, AWS metadata' => ['http://[fd00:ec2::254]/', 'fd00:ec2::254'];
        yield 'IPv6 multicast' => ['http://[ff02::1]/', 'ff02::1'];
        yield 'IPv6 documentation' => ['http://[2001:db8::1]/', '2001:db8::1'];
        yield 'Teredo' => ['http://[2001::1]/', '2001::1'];
        yield '6to4' => ['http://[2002:a00:1::1]/', '2002:a00:1::1'];
        yield 'NAT64 of a private IPv4' => ['http://[64:ff9b::a00:1]/', '64:ff9b::a00:1'];
        yield 'local-use NAT64' => ['http://[64:ff9b:1::a00:1]/', '64:ff9b:1::a00:1'];
        yield 'decimal' => ['http://2130706433/', '127.0.0.1'];
        yield 'hexadecimal' => ['http://0x7f000001/', '127.0.0.1'];
        yield 'octal' => ['http://017700000001/', '127.0.0.1'];
        yield 'dotted octal' => ['http://0177.0.0.1/', '127.0.0.1'];
        yield 'dotted hexadecimal' => ['http://0x7f.0x0.0x0.0x1/', '127.0.0.1'];
        yield 'shorthand' => ['http://127.1/', '127.0.0.1'];
        yield 'private shorthand' => ['http://10.1/', '10.0.0.1'];
        yield 'zero' => ['http://0/', '0.0.0.0'];
        yield 'trailing dot' => ['http://127.0.0.1./', '127.0.0.1'];
    }

    #[DataProvider('nonPublicLiterals')]
    public function testNonPublicAddressIsRefusedBeforeSending(string $url, string $address): void
    {
        $e = $this->refusal(fn() => $this->guarded([self::ok()])->get($url));

        self::assertSame((new Uri($url))->getHost(), $e->getHost());
        // Compared as binary: inet_ntop() spells some IPv6 addresses differently across C libraries.
        self::assertSame(inet_pton($address), inet_pton($e->getAddress()));
        self::assertSame([], $this->sent);
        self::assertSame([], $this->lookups);
    }

    public function testRefusalMessageNamesTheHost(): void
    {
        $client = $this->guarded([], ['intranet.example' => [['10.0.0.5']]]);

        self::assertSame(
            [
                'HTTP request refused: 10.0.0.1 is not a public address',
                'HTTP request refused: [::1] is not a public address',
                'HTTP request refused: 2130706433 is 127.0.0.1, which is not a public address',
                'HTTP request refused: intranet.example resolves to 10.0.0.5, which is not a public address',
            ],
            array_map(
                fn(string $url): string => $this->refusal(static fn() => $client->get($url))->getMessage(),
                ['http://10.0.0.1/', 'http://[::1]/', 'http://2130706433/', 'http://intranet.example/']
            )
        );
    }

    public function testHostResolvingToPublicAddressIsPinnedToIt(): void
    {
        $this->guarded([self::ok()], ['feeds.example' => [[self::PUBLIC_V4, self::PUBLIC_V6]]])
            ->get('https://feeds.example/catalog.xml');

        self::assertSame(['feeds.example'], $this->lookups);
        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo());
        // Only the connection is pinned: the URI, and with it TLS and the Host header, still name the host.
        self::assertSame('https://feeds.example/catalog.xml', (string) $this->sentRequest()->getUri());
        self::assertSame('feeds.example', $this->sentRequest()->getHeaderLine('Host'));
    }

    public function testHostResolvingToPrivateAddressIsRefused(): void
    {
        $client = $this->guarded([self::ok()], ['intranet.example' => [['10.0.0.5', '10.0.0.6']]]);

        $e = $this->refusal(static fn() => $client->get('http://intranet.example/admin'));

        self::assertSame('intranet.example', $e->getHost());
        self::assertSame('10.0.0.5', $e->getAddress());
        self::assertSame('http://intranet.example/admin', (string) $e->getRequest()->getUri());
        self::assertSame([], $this->sent);
    }

    public function testNonPublicAddressesOfAHostAreSkipped(): void
    {
        $this->guarded([self::ok()], ['mixed.example' => [['127.0.0.1', '::1', '10.0.0.1', self::PUBLIC_V4]]])
            ->get('http://mixed.example/');

        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo());
    }

    public function testForceIpResolvePicksTheAddressFamily(): void
    {
        $client = $this->guarded([self::ok(), self::ok()], ['dual.example' => [[self::PUBLIC_V4, self::PUBLIC_V6]]]);

        $client->get('http://dual.example/', ['force_ip_resolve' => 'v6']);
        $client->get('http://dual.example/', ['force_ip_resolve' => 'v4']);

        self::assertSame(['::[' . self::PUBLIC_V6 . ']:'], $this->connectTo(0));
        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo(1));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function unresolvable(): iterable
    {
        yield 'no records' => ['http://nowhere.example/', []];
        yield 'no records of the forced family' => ['http://v6only.example/', ['force_ip_resolve' => 'v4']];
        yield 'IPvFuture literal' => ['http://[v1.fe80]/', []];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('unresolvable')]
    public function testUnresolvableHostIsANetworkError(string $url, array $options): void
    {
        $host = (new Uri($url))->getHost();

        try {
            $this->guarded([self::ok()], ['v6only.example' => [[self::PUBLIC_V6]]])->get($url, $options);
            self::fail('The request was sent');
        } catch (NetworkException $e) {
            self::assertSame("HTTP request failed: Could not resolve host: {$host}", $e->getMessage());
        }

        self::assertSame([], $this->sent);
    }

    public function testRequestWithoutHostIsRefused(): void
    {
        try {
            $this->guarded([self::ok()])->retry(2)->get('relative/path');
            self::fail('The request was sent');
        } catch (RequestException $e) {
            self::assertNotInstanceOf(BlockedHostException::class, $e);
            self::assertSame('HTTP request failed: The request URI has no host', $e->getMessage());
        }

        self::assertSame([], $this->sent);
        self::assertSame([], $this->lookups);
    }

    public function testFailedLookupIsRetriedLikeAConnectFailure(): void
    {
        // Even a POST: nothing was sent.
        $this->guarded([self::ok()], ['flaky.example' => [[], [self::PUBLIC_V4]]])
            ->retry(2)
            ->post('http://flaky.example/hook', ['json' => ['event' => 'paid']]);

        self::assertSame(['flaky.example', 'flaky.example'], $this->lookups);
        self::assertCount(1, $this->sent);
        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo());
    }

    public function testRedirectToPrivateAddressIsRefused(): void
    {
        $client = $this->guarded(
            [new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/iam/']), self::ok()],
            ['images.example' => [[self::PUBLIC_V4]]]
        );

        $e = $this->refusal(static fn() => $client->get('https://images.example/avatar.png'));

        self::assertSame('169.254.169.254', $e->getAddress());
        self::assertSame('http://169.254.169.254/latest/meta-data/iam/', (string) $e->getRequest()->getUri());
        self::assertCount(1, $this->sent);
    }

    public function testRedirectToHostResolvingToPrivateAddressIsRefused(): void
    {
        $client = $this->guarded(
            [new Response(301, ['Location' => 'http://intranet.example/']), self::ok()],
            ['images.example' => [[self::PUBLIC_V4]], 'intranet.example' => [['192.168.1.10']]]
        );

        $e = $this->refusal(static fn() => $client->get('https://images.example/avatar.png'));

        self::assertSame('intranet.example', $e->getHost());
        self::assertSame('192.168.1.10', $e->getAddress());
        self::assertCount(1, $this->sent);
    }

    public function testEveryRedirectHopIsPinnedToItsOwnAddress(): void
    {
        $this->guarded(
            [new Response(302, ['Location' => 'https://cdn.example/avatar.png']), self::ok()],
            ['images.example' => [[self::PUBLIC_V4]], 'cdn.example' => [[self::PUBLIC_V6]]]
        )->get('https://images.example/avatar.png');

        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo(0));
        self::assertSame(['::[' . self::PUBLIC_V6 . ']:'], $this->connectTo(1));
    }

    /**
     * DNS rebinding: the name answers with a public address for the check and with a private one right after.
     * The transport never asks again, since it connects to the checked address, and a new lookup is checked anew.
     */
    public function testDnsRebindingCannotSwapInAPrivateAddress(): void
    {
        $client = $this->guarded(
            [new Response(302, ['Location' => '/next']), self::ok()],
            ['rebind.example' => [[self::PUBLIC_V4], ['127.0.0.1']]]
        );

        $e = $this->refusal(static fn() => $client->get('http://rebind.example/start'));

        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo(0));
        self::assertSame('127.0.0.1', $e->getAddress());
        self::assertSame('http://rebind.example/next', (string) $e->getRequest()->getUri());
        self::assertCount(1, $this->sent);
    }

    public function testRetryChecksTheHostAgain(): void
    {
        $client = $this->guarded(
            [self::connectError(), self::ok()],
            ['rebind.example' => [[self::PUBLIC_V4], ['10.0.0.1']]]
        )->retry(3);

        $e = $this->refusal(static fn() => $client->get('http://rebind.example/'));

        self::assertSame('10.0.0.1', $e->getAddress());
        self::assertSame(['rebind.example', 'rebind.example'], $this->lookups);
        self::assertCount(1, $this->sent);
    }

    public function testRefusalIsNeitherRetriedNorLogged(): void
    {
        $lines = [];
        $client = $this->guarded([self::ok()])
            ->retry(3)
            ->withLogging(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->refusal(static fn() => $client->get('http://127.0.0.1/'));

        self::assertSame([], $lines);
        self::assertSame([], $this->sent);
    }

    public function testPinReplacesConnectToOfTheCurlOption(): void
    {
        $this->guarded([self::ok()], ['feeds.example' => [[self::PUBLIC_V4]]])->get('http://feeds.example/', [
            'curl' => [\CURLOPT_CONNECT_TO => ['::127.0.0.1:'], \CURLOPT_TCP_NODELAY => true],
        ]);

        self::assertSame(['::' . self::PUBLIC_V4 . ':'], $this->connectTo());
        self::assertTrue($this->sent[0]['options']['curl'][\CURLOPT_TCP_NODELAY]);
    }

    public function testAsynchronousRequestIsRejected(): void
    {
        $promise = $this->guarded([self::ok()])->getAsync('http://10.0.0.1/');

        $this->refusal(static fn() => $promise->wait());
        self::assertSame([], $this->sent);
    }

    public function testRefusalIsAPsr18RequestException(): void
    {
        $e = $this->refusal(fn() => $this->guarded([self::ok()])->sendRequest(new Request('GET', 'http://[::1]/')));

        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
    }

    public function testGuzzleClientFromGetClientIsGuarded(): void
    {
        $guzzle = $this->guarded([self::ok()])->getClient();

        $this->refusal(static fn() => $guzzle->request('GET', 'http://192.168.0.1/'));
        self::assertSame([], $this->sent);
    }

    public function testRegisteredSecretsAreMaskedInTheMessage(): void
    {
        $client = $this->guarded([self::ok()], ['tok3n.hooks.example' => [['10.0.0.1']]])->withRedaction('tok3n');

        $e = $this->refusal(static fn() => $client->get('https://tok3n.hooks.example/'));

        self::assertSame(
            'HTTP request refused: ***.hooks.example resolves to 10.0.0.1, which is not a public address',
            $e->getMessage()
        );
        self::assertSame('tok3n.hooks.example', $e->getHost());
    }

    public function testRegisteredHostSecretsStayOutOfTraceArguments(): void
    {
        $url = 'https://secret-token.hooks.example/';
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $refused = $this->guarded([], ['secret-token.hooks.example' => [['10.0.0.1']]])
                ->withRedaction('secret-token');
            $e = $this->refusal(static fn() => $refused->get($url));
            self::assertStringNotContainsString('secret-token', self::traceArguments($e));

            // A resolver that answers with something other than addresses fails inside the lookup.
            $misresolved = $this->client([self::ok()], '')
                ->withRedaction('secret-token')
                ->withPublicHostsOnly(static fn(string $host): array => ['localhost']);
            try {
                $misresolved->get($url);
                self::fail('The request was sent');
            } catch (\UnexpectedValueException $e) {
                self::assertStringNotContainsString('secret-token', self::traceArguments($e));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    public function testResolverMustReturnIpAddresses(): void
    {
        $client = $this->client([self::ok()], '')->withPublicHostsOnly(static fn(string $host): array => ['localhost']);

        $this->expectException(\UnexpectedValueException::class);
        $client->get('http://feeds.example/');
    }

    public function testClientItWasDerivedFromStaysUnrestricted(): void
    {
        $plain = $this->client([self::ok()], '');
        $plain->withPublicHostsOnly(static fn(string $host): array => []);

        $plain->get('http://127.0.0.1/');

        self::assertArrayNotHasKey('curl', $this->sent[0]['options']);
    }

    /**
     * Guzzle routes the stream option to its stream handler, which cannot pin a connection and refuses the request.
     */
    public function testStreamHandlerRefusesTheRequest(): void
    {
        if (!(bool) ini_get('allow_url_fopen')) {
            self::markTestSkipped('Guzzle has no stream handler without allow_url_fopen');
        }

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('stream handler');

        HttpClient::create('', ['connect_timeout' => 1, 'timeout' => 1])
            ->withPublicHostsOnly()
            ->get('http://' . self::PUBLIC_V4 . '/', ['stream' => true]);
    }

    /**
     * A client restricted to public hosts that resolves names from $dns: successive answers per host,
     * the last one repeated. A host missing from $dns has no records.
     *
     * @param list<mixed> $queue
     * @param array<string, non-empty-list<list<string>>> $dns
     */
    private function guarded(array $queue, array $dns = []): HttpClient
    {
        return $this->client($queue, '')->withPublicHostsOnly(function (string $host) use (&$dns): array {
            $this->lookups[] = $host;
            if (!isset($dns[$host])) {
                return [];
            }

            return count($dns[$host]) > 1 ? (array) array_shift($dns[$host]) : $dns[$host][0];
        });
    }

    /**
     * The CURLOPT_CONNECT_TO value the handler received with an attempt.
     */
    private function connectTo(int $attempt = 0): mixed
    {
        return $this->sent[$attempt]['options']['curl'][\CURLOPT_CONNECT_TO] ?? null;
    }

    private function refusal(callable $send): BlockedHostException
    {
        try {
            $send();
        } catch (BlockedHostException $e) {
            return $e;
        }

        self::fail('The request was not refused');
    }
}
