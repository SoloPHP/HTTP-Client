<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use Psr\Log\AbstractLogger;
use Solo\HttpClient\Exception\NetworkException;

/**
 * Issue #8: secrets from query strings leaked into logs and exception messages; PSR-3 levels were lost.
 * Review: secrets in the URL path were not redactable.
 */
final class LoggingTest extends TestCase
{
    public function testQueryValuesAndUserInfoAreRedactedInLogs(): void
    {
        $lines = [];
        $client = $this->client([self::ok(), self::ok()])->withLogging(self::collectInto($lines));

        $client->get('/echo?api_key=SECRET-IN-QUERY&page=2');
        $client->get('https://user:PASSWORD@api.test/x?SECRET-BARE');

        self::assertCount(2, $lines);
        self::assertStringContainsString('GET https://api.test/echo?api_key=***&page=*** HTTP/1.1 200', $lines[0]);
        self::assertStringNotContainsString('SECRET', implode("\n", $lines));
        self::assertStringNotContainsString('PASSWORD', implode("\n", $lines));
    }

    public function testPsr3LoggerReceivesProperLevels(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $client = $this->client([self::ok(), self::connectError(7)])->withLogging($logger);
        $client->get('/ok');
        try {
            $client->get('/fail?token=SECRET');
        } catch (NetworkException) {
        }

        self::assertSame(['info', 'error'], array_column($logger->records, 0));
        self::assertStringNotContainsString('SECRET', $logger->records[1][1]);
    }

    public function testDefaultLoggerWritesToErrorLog(): void
    {
        $file = $this->tempFile('');
        $previous = ini_set('error_log', $file);

        try {
            $this->client([self::ok()])->withLogging()->get('/x?key=SECRET');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $log = (string) file_get_contents($file);
        self::assertStringContainsString('[HttpClient] INFO: GET https://api.test/x?key=*** HTTP/1.1 200', $log);
        self::assertStringNotContainsString('SECRET', $log);
    }

    public function testWithRedactionMasksPathSecretsInLogsAndExceptions(): void
    {
        $lines = [];
        $client = $this->client([self::ok(), self::connectError(7)])
            ->withRedaction('123456:SECRET-TOKEN', '')
            ->withLogging(self::collectInto($lines));

        $client->get('/bot123456:SECRET-TOKEN/sendMessage');
        try {
            $client->get('/bot123456:SECRET-TOKEN/getUpdates');
            self::fail('Expected exception');
        } catch (NetworkException $e) {
            self::assertStringNotContainsString('SECRET-TOKEN', $e->getMessage());
            self::assertStringContainsString('https://api.test/bot***/getUpdates', $e->getMessage());
        }

        self::assertStringContainsString('GET https://api.test/bot***/sendMessage HTTP/1.1 200', $lines[0]);
        self::assertStringNotContainsString('SECRET-TOKEN', implode("\n", $lines));
    }

    public function testRedactionAlsoMasksUrlEncodedSecret(): void
    {
        $lines = [];
        $this->client([self::ok()])
            ->withRedaction('se cret/€')
            ->withLogging(self::collectInto($lines))
            ->get('/files/' . rawurlencode('se cret/€'));

        self::assertStringContainsString('/files/***', $lines[0]);
    }

    public function testExceptionMessageDoesNotContainQuerySecrets(): void
    {
        try {
            $this->client([self::connectError(7)])->get('/x?api_key=SECRET');
            self::fail('Expected exception');
        } catch (NetworkException $e) {
            self::assertStringContainsString('https://api.test/x?api_key=***', $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function collectInto(array &$lines): callable
    {
        return static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
    }
}
