<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Solo\HttpClient\Exception\ResponseTooLargeException;

/**
 * Counts the bytes written to a sink, or read from a streamed body, and throws once they would go past the limit.
 * A sink never receives the bytes past the limit.
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
        $data = $this->stream->read($length);
        if ($this->countReads) {
            $this->count(strlen($data));
        }

        return $data;
    }

    private function count(int $bytes): void
    {
        $this->bytes += $bytes;
        if ($this->bytes > $this->limit) {
            throw ($this->tooLarge)();
        }
    }
}
