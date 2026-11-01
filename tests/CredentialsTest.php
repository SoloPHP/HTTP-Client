<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Solo\HttpClient\HttpClient;

/**
 * Issue #6: client-wide credentials were sent to any absolute URL.
 * Review: token re-added on cross-origin redirects without base_uri, UriInterface base_uri,
 * digest/NTLM not bound, array Authorization rejected, control characters leaking the token.
 */
final class CredentialsTest extends TestCase
{
    public function testTokenIsSentToBaseOrigin(): void
    {
        $client = $this->client([self::ok(), self::ok()])->withToken('SECRET');

        $client->get('/users');
        $client->get('https://API.test:443/other');

        self::assertSame('Bearer SECRET', $this->sentRequest(0)->getHeaderLine('Authorization'));
        self::assertSame('Bearer SECRET', $this->sentRequest(1)->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignOrigins(): iterable
    {
        yield 'other host' => ['https://evil.test/collect'];
        yield 'subdomain' => ['https://evil.api.test/'];
        yield 'http downgrade' => ['http://api.test/'];
        yield 'other port' => ['https://api.test:8443/'];
    }

    #[DataProvider('foreignOrigins')]
    public function testTokenIsNotSentToForeignOrigin(string $url): void
    {
        $this->client([self::ok()])->withToken('SECRET')->get($url);

        self::assertFalse($this->sentRequest()->hasHeader('Authorization'));
    }

    #[DataProvider('foreignOrigins')]
    public function testBasicAuthIsNotSentToForeignOrigin(string $url): void
    {
        $client = $this->client([self::ok(), self::ok()])->withBasicAuth('user', 'pass');

        $client->get($url);
        $client->get('/');

        self::assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
        self::assertSame('Basic ' . base64_encode('user:pass'), $this->sentRequest(1)->getHeaderLine('Authorization'));
    }

    public function testCredentialsFromConstructorConfigAreBoundToOrigin(): void
    {
        $byHeader = $this->client([self::ok(), self::ok()], config: ['headers' => ['authorization' => 'Bearer A']]);
        $byHeader->get('https://evil.test/');
        $byHeader->get('/');

        $byAuth = $this->client([self::ok(), self::ok()], config: ['auth' => ['user', 'pass']]);
        $byAuth->get('https://evil.test/');
        $byAuth->get('/');

        self::assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
        self::assertSame('Bearer A', $this->sentRequest(1)->getHeaderLine('Authorization'));
        self::assertFalse($this->sentRequest(2)->hasHeader('Authorization'));
        self::assertSame('Basic ' . base64_encode('user:pass'), $this->sentRequest(3)->getHeaderLine('Authorization'));
    }

    public function testAuthorizationViaWithHeadersIsBoundToOrigin(): void
    {
        $this->client([self::ok()])->withHeaders(['AUTHORIZATION' => 'Bearer X'])->get('https://evil.test/');

        self::assertFalse($this->sentRequest()->hasHeader('Authorization'));
    }

    public function testCrossOriginRedirectDoesNotCarryToken(): void
    {
        $this->client([new Response(302, ['Location' => 'https://evil.test/landing']), self::ok()])
            ->withToken('SECRET')
            ->get('/start');

        self::assertSame('Bearer SECRET', $this->sentRequest(0)->getHeaderLine('Authorization'));
        self::assertSame('evil.test', $this->sentRequest(1)->getUri()->getHost());
        self::assertFalse($this->sentRequest(1)->hasHeader('Authorization'));
    }

    public function testPerRequestAuthorizationIsKept(): void
    {
        $this->client([self::ok()])
            ->withToken('CLIENT-WIDE')
            ->get('https://partner.test/', ['headers' => ['Authorization' => 'Bearer PER-REQUEST']]);

        self::assertSame('Bearer PER-REQUEST', $this->sentRequest()->getHeaderLine('Authorization'));
    }

    public function testWithoutBaseUriTokenIsSentEverywhere(): void
    {
        $this->client([self::ok()], '')->withToken('SECRET')->get('https://any.test/');

        self::assertSame('Bearer SECRET', $this->sentRequest()->getHeaderLine('Authorization'));
    }

    public function testCrlfInTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HttpClient('https://api.test'))->withToken("x\r\nX-Injected: yes");
    }

    public function testCrossOriginRedirectWithoutBaseUriDoesNotCarryToken(): void
    {
        $this->client([new Response(302, ['Location' => 'https://evil.test/x']), self::ok()], '')
            ->withToken('SECRET')
            ->get('https://api.test/start');

        self::assertSame('Bearer SECRET', $this->sentRequest(0)->getHeaderLine('Authorization'));
        self::assertSame('evil.test', $this->sentRequest(1)->getUri()->getHost());
        self::assertFalse($this->sentRequest(1)->hasHeader('Authorization'));
    }

    public function testSameOriginRedirectKeepsToken(): void
    {
        $this->client([new Response(302, ['Location' => '/next']), self::ok()])
            ->withToken('SECRET')
            ->get('/start');

        self::assertSame('/next', $this->sentRequest(1)->getUri()->getPath());
        self::assertSame('Bearer SECRET', $this->sentRequest(1)->getHeaderLine('Authorization'));
    }

    public function testUriInterfaceBaseUriBindsCredentials(): void
    {
        $client = $this->client([self::ok(), self::ok()], '', ['base_uri' => new Uri('https://api.test')])
            ->withToken('SECRET');

        $client->get('https://evil.test/collect');
        $client->get('/me');

        self::assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
        self::assertSame('Bearer SECRET', $this->sentRequest(1)->getHeaderLine('Authorization'));
    }

    public function testArrayAuthorizationInConfigIsAccepted(): void
    {
        $this->client([self::ok()], config: ['headers' => ['Authorization' => ['Bearer X']]])->get('/');

        self::assertSame(['Bearer X'], $this->sentRequest()->getHeader('Authorization'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function curlAuthTypes(): iterable
    {
        yield 'digest' => ['digest', \defined('CURLAUTH_DIGEST') ? \CURLAUTH_DIGEST : 0];
        yield 'ntlm' => ['ntlm', \defined('CURLAUTH_NTLM') ? \CURLAUTH_NTLM : 0];
    }

    #[DataProvider('curlAuthTypes')]
    #[RequiresPhpExtension('curl')]
    public function testDigestAndNtlmAuthAreBoundToOrigin(string $type, int $curlAuth): void
    {
        $client = $this->client([self::ok(), self::ok()], config: ['auth' => ['user', 'pass', $type]]);

        $client->get('https://evil.test/');
        $client->get('/');

        $foreign = $this->sent[0]['options']['curl'] ?? [];
        self::assertIsArray($foreign);
        self::assertArrayNotHasKey(\CURLOPT_USERPWD, $foreign);

        $own = $this->sent[1]['options']['curl'] ?? [];
        self::assertIsArray($own);
        self::assertSame($curlAuth, $own[\CURLOPT_HTTPAUTH] ?? null);
        self::assertSame('user:pass', $own[\CURLOPT_USERPWD] ?? null);
    }

    public function testControlCharacterInTokenIsRejectedWithoutLeakingIt(): void
    {
        try {
            (new HttpClient('https://api.test'))->withToken("TOKEN-VALUE\x01");
            self::fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('TOKEN-VALUE', $e->getMessage());
        }
    }

    public function testInvalidHeaderNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HttpClient('https://api.test'))->withHeaders(["X-Bad\r\nX-Injected" => 'v']);
    }

    public function testInvalidHeaderInConstructorConfigIsRejectedWithoutLeakingIt(): void
    {
        try {
            new HttpClient('https://api.test', ['headers' => ['X-Api-Key' => "KEY-VALUE\r\n"]]);
            self::fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('KEY-VALUE', $e->getMessage());
        }
    }

    public function testInvalidPerRequestHeaderIsRejectedWithoutLeakingIt(): void
    {
        $client = $this->client([self::ok(), self::ok()]);
        $calls = [
            static fn() => $client->get('/', ['headers' => ['Authorization' => "Bearer SECRET-VALUE\n"]]),
            static fn() => $client->getAsync('/', ['headers' => ['X-Api-Key' => ["SECRET-VALUE\r\n"]]]),
            static fn() => $client->send(new Request('GET', '/'), ['headers' => ['X-Api-Key' => "SECRET-VALUE\x01"]]),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected exception');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('SECRET-VALUE', $e->getMessage());
            }
        }
        self::assertCount(0, $this->sent);
    }

    public function testPerRequestHeaderValuesAreStringsAsInGuzzle(): void
    {
        $client = $this->client([self::ok()]);
        $client->get('/', ['headers' => ['X-List' => ['a', 'b'], 'X-Empty' => '']]);

        self::assertSame('a, b', $this->sentRequest()->getHeaderLine('X-List'));
        self::assertTrue($this->sentRequest()->hasHeader('X-Empty'));

        foreach ([5, null, [], ['a', 1]] as $value) {
            try {
                $client->get('/', ['headers' => ['X-Value' => $value]]);
                self::fail('Expected exception for ' . json_encode($value));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('X-Value', $e->getMessage());
            }
        }
        self::assertCount(1, $this->sent);
    }

    /**
     * With zend.exception_ignore_args off, as in development, PHP records call arguments in stack traces.
     */
    public function testRejectedSecretsStayOutOfStackTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $client = $this->client([self::ok()]);
        $calls = [
            static fn() => $client->withToken("TOKEN-VALUE\n"),
            static fn() => $client->withBasicAuth('user', "TOKEN-VALUE\x00"),
            static fn() => $client->get('/?key=TOKEN-VALUE', ['headers' => ['X-Token' => "TOKEN-VALUE\r"]]),
            static fn() => new HttpClient('https://api.test', ['headers' => ['X-Api-Key' => "TOKEN-VALUE\n"]]),
        ];

        try {
            foreach ($calls as $call) {
                try {
                    $call();
                    self::fail('Expected exception');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringNotContainsString('TOKEN-VALUE', $e->getMessage());
                    self::assertStringNotContainsString('TOKEN-VALUE', self::traceArguments($e));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testPerRequestAuthOptionTakesPrecedence(): void
    {
        $this->client([self::ok()])->withToken('CLIENT-TOKEN')->get('/', ['auth' => ['user', 'pass']]);

        self::assertSame('Basic ' . base64_encode('user:pass'), $this->sentRequest()->getHeaderLine('Authorization'));
    }

    public function testBasicAuthFollowsRfc7617(): void
    {
        $client = new HttpClient('https://api.test');
        self::assertNotSame($client, $client->withBasicAuth('user', 'pa:ss'));

        foreach ([['us:er', 'pass'], ["user\n", 'pass'], ['user', "SECRET\x00"]] as [$username, $password]) {
            try {
                $client->withBasicAuth($username, $password);
                self::fail('Expected exception for ' . json_encode($username));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new HttpClient('https://api.test', ['auth' => ['us:er', 'pass']]);
    }
}
