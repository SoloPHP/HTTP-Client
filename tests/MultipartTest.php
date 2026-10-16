<?php

declare(strict_types=1);

namespace Solo\HttpClient\Tests;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use Solo\HttpClient\File;

/**
 * Issues #1 (local file read via "path" key), #7 (CRLF injection in part headers),
 * #11 (TypeError on integer keys); review: legit names/paths rejected, caller streams closed on failure.
 */
final class MultipartTest extends TestCase
{
    public function testArrayWithPathKeyFromUserInputIsSentAsTextNotAsFile(): void
    {
        $secret = $this->tempFile('TOP-SECRET-CONTENT');
        // What PHP builds from a form field: comment[path]=/some/file
        parse_str('name=John&comment[path]=' . urlencode($secret), $post);

        $this->client([self::ok()])->postMultipart('/forward', $post);

        $body = $this->sentBody();
        self::assertStringNotContainsString('TOP-SECRET-CONTENT', $body);
        self::assertStringNotContainsString('filename=', $body);
        self::assertStringContainsString('name="comment[path]"', $body);
        self::assertStringContainsString($secret, $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function streamWrapperPaths(): iterable
    {
        yield 'php://filter' => ['php://filter/convert.base64-encode/resource=/etc/passwd'];
        yield 'http:// (SSRF)' => ['http://127.0.0.1/internal-secret'];
        yield 'phar://' => ['phar:///tmp/evil.phar/file.txt'];
        yield 'data:' => ['data:text/plain,hello'];
        yield 'file://' => ['file:///etc/passwd'];
        yield 'expect://' => ['expect://id'];
    }

    #[DataProvider('streamWrapperPaths')]
    public function testStreamWrappersAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream wrappers are not allowed');
        File::fromPath($path);
    }

    public function testMissingFileDirectoryAndNulByteAreRejected(): void
    {
        foreach (['/nonexistent/file.txt', sys_get_temp_dir(), $this->tempFile('x') . "\0.jpg", ''] as $path) {
            try {
                File::fromPath($path);
                self::fail('Expected exception for ' . json_encode($path));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testExplicitFileIsUploaded(): void
    {
        $path = $this->tempFile('file-body');

        $this->client([self::ok()])->postMultipart('/upload', [
            'title' => 'Report',
            'doc' => File::fromPath($path, 'report.txt', 'text/plain; charset=utf-8'),
        ]);

        $body = $this->sentBody();
        self::assertStringContainsString('name="title"', $body);
        self::assertStringContainsString('name="doc"; filename="report.txt"', $body);
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $body);
        self::assertStringContainsString('file-body', $body);
    }

    public function testFileFromStreamIsUploaded(): void
    {
        $this->client([self::ok()])->putMultipart('/upload', [
            'doc' => File::fromStream(Utils::streamFor('stream-body'), 'a.csv', 'text/csv'),
        ]);

        self::assertSame('PUT', $this->sentRequest()->getMethod());
        self::assertStringContainsString('filename="a.csv"', $this->sentBody());
        self::assertStringContainsString('stream-body', $this->sentBody());
    }

    public function testQuotesAndCrlfInFilenameAreEncodedNotInjected(): void
    {
        $this->client([self::ok()])->postMultipart('/upload', [
            'doc' => File::fromPath($this->tempFile('x'), "a.txt\"\r\nX-Injected: yes"),
        ]);

        $body = $this->sentBody();
        self::assertStringContainsString('filename="a.txt%22%0D%0AX-Injected: yes"', $body);
        self::assertStringNotContainsString("\r\nX-Injected: yes", $body);
    }

    public function testLegitFilenameWithQuotesAndBackslashIsAccepted(): void
    {
        $this->client([self::ok()])->postMultipart('/upload', [
            'doc' => File::fromPath($this->tempFile('x'), 'John\'s "CV" \\ 2024.pdf'),
        ]);

        self::assertStringContainsString('filename="John\'s %22CV%22 %5C 2024.pdf"', $this->sentBody());
    }

    public function testCrlfInMimeTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        File::fromPath($this->tempFile('x'), 'a.txt', "text/plain\r\nX-Injected: yes");
    }

    public function testEmptyFilenameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        File::fromStream(Utils::streamFor('x'), '');
    }

    public function testCrlfInFieldNameIsEncoded(): void
    {
        $this->client([self::ok()])->postMultipart('/upload', ["x\"\r\nX-Injected: yes" => 'v']);

        $body = $this->sentBody();
        self::assertStringContainsString('name="x%22%0D%0AX-Injected: yes"', $body);
        self::assertStringNotContainsString("\r\nX-Injected: yes", $body);
    }

    public function testLocalPathWithColonIsAccepted(): void
    {
        $dir = sys_get_temp_dir() . '/solo-colon-' . getmypid();
        @mkdir($dir);
        file_put_contents("{$dir}/report:2024.txt", 'colon-file');
        $cwd = (string) getcwd();
        chdir($dir);

        try {
            $file = File::fromPath('report:2024.txt');
        } finally {
            chdir($cwd);
            @unlink("{$dir}/report:2024.txt");
            @rmdir($dir);
        }

        self::assertSame('report:2024.txt', $file->filename());
    }

    public function testFailedBuildDoesNotTouchCallerStreams(): void
    {
        $stream = Utils::streamFor('caller-owned');
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        try {
            $this->client([self::ok()])->postMultipart('/upload', [
                'a' => File::fromStream($stream, 'a.txt'),
                'b' => File::fromStream($resource, 'b.txt'),
                'bad' => new \stdClass(),
            ]);
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $this->sent);
        }

        self::assertTrue($stream->isReadable());
        self::assertSame('caller-owned', (string) $stream);
        self::assertIsResource($resource);
        fclose($resource);
    }

    public function testIntegerAndNumericKeysAndNestedArraysWork(): void
    {
        $path = $this->tempFile('x');

        $this->client([self::ok()])->patchMultipart('/upload', [
            File::fromPath($path),
            '2024' => File::fromPath($path),
            'tags' => ['a', 'b'],
            'meta' => ['flag' => true, 'count' => 3],
        ]);

        $body = $this->sentBody();
        foreach (['name="0"', 'name="2024"', 'name="tags[0]"', 'name="tags[1]"', 'name="meta[flag]"'] as $part) {
            self::assertStringContainsString($part, $body);
        }
    }

    public function testUnsupportedValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([self::ok()])->postMultipart('/upload', ['obj' => new \stdClass()]);
    }

    public function testRawMultipartOptionIsProtectedOnSyncAndAsyncPaths(): void
    {
        $client = $this->client([self::ok(), self::ok()]);
        $parts = [
            ['name' => "a\"\r\nX-Injected: yes", 'contents' => 'v'],
            ['name' => 'doc', 'contents' => 'x', 'filename' => "f\"\r\nX-Injected: yes"],
            ['name' => 'file', 'contents' => File::fromPath($this->tempFile('file-body'), 'r.txt', 'text/plain')],
            ['name' => 'nested', 'contents' => ['key' => 'value']],
        ];

        $client->post('/upload', ['multipart' => $parts]);
        $client->postAsync('/upload', ['multipart' => $parts])->wait();

        foreach ([$this->sentBody(0), $this->sentBody(1)] as $body) {
            self::assertStringContainsString('name="a%22%0D%0AX-Injected: yes"', $body);
            self::assertStringContainsString('filename="f%22%0D%0AX-Injected: yes"', $body);
            self::assertStringContainsString('name="file"; filename="r.txt"', $body);
            self::assertStringContainsString('file-body', $body);
            self::assertStringContainsString('name="nested[key]"', $body);
            self::assertStringNotContainsString("\r\nX-Injected: yes", $body);
        }
    }

    public function testRawMultipartPartHeaderWithCrlfIsRejectedBeforeSending(): void
    {
        try {
            $this->client([self::ok()])->post('/upload', ['multipart' => [
                ['name' => 'a', 'contents' => 'v', 'headers' => ['X-A' => "v\r\nX-Injected: yes"]],
            ]]);
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $this->sent);
        }
    }
}
