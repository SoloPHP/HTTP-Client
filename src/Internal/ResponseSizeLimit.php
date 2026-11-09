<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Solo\HttpClient\Exception\ResponseTooLargeException;

/**
 * The layer of withMaxResponseSize(), inside retries, so every attempt counts from zero. A response whose
 * Content-Length is over the limit is refused as soon as its headers arrive. Otherwise the body is counted as the
 * handler writes it to the sink, after decoding, so a compressed body cannot expand past the limit; a streamed
 * body is counted as it is read.
 *
 * @internal
 */
final class ResponseSizeLimit
{
    /** Statuses whose Content-Length describes a body they do not carry */
    private const array BODILESS_STATUSES = [204, 304];

    public static function middleware(int $limit): callable
    {
        return static fn(callable $handler): callable => static function (
            #[\SensitiveParameter] RequestInterface $request,
            #[\SensitiveParameter] array $options
        ) use (
            $handler,
            $limit
        ): PromiseInterface {
            $tooLarge = static fn(): ResponseTooLargeException => new ResponseTooLargeException($request, $limit);
            $streamed = ($options['stream'] ?? false) === true;

            $onHeaders = $options['on_headers'] ?? null;
            // An invalid on_headers is left for Guzzle to refuse.
            if ($onHeaders === null || is_callable($onHeaders)) {
                $options['on_headers'] = static function (
                    ResponseInterface $response,
                    mixed ...$rest
                ) use (
                    $request,
                    $limit,
                    $onHeaders,
                    $tooLarge
                ): void {
                    if (self::announcesMore($request, $response, $limit)) {
                        throw $tooLarge();
                    }
                    if ($onHeaders !== null) {
                        $onHeaders($response, ...$rest);
                    }
                };
            }

            if (!$streamed) {
                $options['sink'] = self::limitedSink($options['sink'] ?? null, $limit, $tooLarge);
            }

            return Create::promiseFor($handler($request, $options))->then(
                static fn(mixed $response): mixed => $streamed && $response instanceof ResponseInterface
                    ? $response->withBody(new SizeLimitedStream($response->getBody(), $limit, true, $tooLarge))
                    : $response,
                // The handler reports the refusal wrapped in its own exception: the caller gets the refusal itself.
                static fn(mixed $reason): PromiseInterface => Create::rejectionFor(self::refusal($reason) ?? $reason)
            );
        };
    }

    /**
     * Whether the Content-Length of a response that carries a body is over the limit. Compared as digits, so a
     * length past PHP_INT_MAX cannot wrap around.
     */
    private static function announcesMore(RequestInterface $request, ResponseInterface $response, int $limit): bool
    {
        $status = $response->getStatusCode();
        $length = trim($response->getHeaderLine('Content-Length'));
        if (
            strtoupper($request->getMethod()) === 'HEAD'
            || $status < 200
            || in_array($status, self::BODILESS_STATUSES, true)
            || preg_match('/^\d+$/D', $length) !== 1
        ) {
            return false;
        }

        $length = ltrim($length, '0');
        $max = (string) $limit;

        return strlen($length) === strlen($max) ? strcmp($length, $max) > 0 : strlen($length) > strlen($max);
    }

    /**
     * The sink option as a stream counting what is written to it. A path whose directory does not exist, or a
     * value of another type, is left for Guzzle to refuse.
     *
     * @param \Closure(): ResponseTooLargeException $tooLarge
     */
    private static function limitedSink(mixed $sink, int $limit, \Closure $tooLarge): mixed
    {
        $stream = match (true) {
            $sink === null => Utils::streamFor(Utils::tryFopen('php://temp', 'w+')),
            $sink instanceof StreamInterface => $sink,
            // A resource stays the caller's to close, as Guzzle leaves it.
            is_resource($sink) => self::borrowed($sink),
            is_string($sink) && is_dir(dirname($sink)) => new LazyOpenStream($sink, 'w+'),
            default => null,
        };

        return $stream === null ? $sink : new SizeLimitedStream($stream, $limit, false, $tooLarge);
    }

    /**
     * @param resource $resource
     */
    private static function borrowed($resource): StreamInterface
    {
        $stream = Utils::streamFor($resource);

        return FnStream::decorate($stream, ['close' => static function () use ($stream): void {
            $stream->detach();
        }]);
    }

    private static function refusal(mixed $reason): ?ResponseTooLargeException
    {
        for ($e = $reason instanceof \Throwable ? $reason : null; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ResponseTooLargeException) {
                return $e;
            }
        }

        return null;
    }
}
