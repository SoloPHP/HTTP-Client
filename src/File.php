<?php

declare(strict_types=1);

namespace Solo\HttpClient;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A file part for multipart requests.
 *
 * Files are attached only through this class, never inferred from request data,
 * so user input like `avatar[path]=/etc/passwd` is always sent as plain text.
 */
final class File
{
    /**
     * @param string|StreamInterface|resource $source Real path of a local file, or an opened stream
     */
    private function __construct(
        private readonly mixed $source,
        private readonly string $filename,
        private readonly ?string $mimeType
    ) {
        if ($filename === '') {
            throw new \InvalidArgumentException('File name must not be empty');
        }
        if ($mimeType !== null) {
            self::assertMimeType($mimeType);
        }
    }

    /**
     * Attach a regular local file. Stream wrappers (php://, http://, phar://, data: ...) are rejected.
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $mimeType = null): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('File path must be a non-empty string without NUL bytes');
        }

        // PHP treats only "scheme://..." and "data:..." as stream wrappers; "report:2024.txt" is a local file.
        if (preg_match('~^(?:[a-z][a-z0-9+.\-]*://|data:)~i', $path) === 1) {
            throw new \InvalidArgumentException('Stream wrappers are not allowed in file path');
        }

        // The absolute real path is opened later, so a wrapper can never be reached through fopen().
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new \InvalidArgumentException('File does not exist or is not readable: ' . basename($path));
        }

        return new self($realPath, $filename ?? basename($realPath), $mimeType);
    }

    /**
     * Attach contents of an already opened stream.
     *
     * @param StreamInterface|resource $stream
     */
    public static function fromStream($stream, string $filename, ?string $mimeType = null): self
    {
        if (!$stream instanceof StreamInterface && !is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource or ' . StreamInterface::class);
        }

        return new self($stream, $filename, $mimeType);
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * @internal
     */
    public function openStream(): StreamInterface
    {
        return Utils::streamFor(is_string($this->source) ? Utils::tryFopen($this->source, 'rb') : $this->source);
    }

    private static function assertMimeType(string $mimeType): void
    {
        $token = "[A-Za-z0-9!#$&^_.+\\-]+";
        $pattern = "~^{$token}/{$token}(?:\\s*;\\s*{$token}=(?:{$token}|\"[^\"\\\\\\x00-\\x1F\\x7F]*\"))*$~D";

        if (preg_match($pattern, $mimeType) !== 1) {
            throw new \InvalidArgumentException('Invalid MIME type');
        }
    }
}
