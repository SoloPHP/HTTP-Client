<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Solo\HttpClient\Exception\ResponseTooLargeException;

/**
 * Counts the bytes written to a sink, or the read position in a streamed body, and throws once they would go past
 * the limit. A sink never receives the bytes past the limit; a streamed body read again after a seek is not
 * counted twice, and one read never asks the body for more than one byte past the limit.
 *
 * @internal
 */
final class SizeLimitedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $bytes = 0;

    /**
     * @param \Closure(): ResponseTooLargeException $tooLarge
     */
    public function __construct(
        private StreamInterface $stream,
        private readonly int $limit,
        private readonly bool $countReads,
        private readonly \Closure $tooLarge
    ) {
        // A streamed body is counted by its position, from wherever the stream stands when it arrives.
        if ($countReads && $stream->isSeekable()) {
            $this->bytes = $stream->tell();
        }
    }

    #[\Override]
    public function write(string $string): int
    {
        if (!$this->countReads) {
            $this->count(strlen($string));
        }

        return $this->stream->write($string);
    }

    #[\Override]
    public function read(int $length): string
    {
        if (!$this->countReads) {
            return $this->stream->read($length);
        }

        // One byte past the limit is enough to tell it was passed, without allocating the caller's full request.
        // $remaining + 1 is taken only below the length asked for, so it cannot overflow.
        $remaining = max(0, $this->limit - $this->bytes);
        $data = $this->stream->read($length > $remaining ? $remaining + 1 : $length);
        $this->count(strlen($data));

        return $data;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        if ($this->countReads) {
            $this->bytes = $this->stream->tell();
        }
    }

    private function count(int $bytes): void
    {
        $this->bytes += $bytes;
        if ($this->bytes > $this->limit) {
            throw ($this->tooLarge)();
        }
    }
}
