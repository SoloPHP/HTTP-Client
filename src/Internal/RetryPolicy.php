<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException as GuzzleNetworkException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decides which requests can be retried and how long to wait between attempts.
 *
 * @internal
 */
final class RetryPolicy
{
    private const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'];

    public static function middleware(
        int $maxRetries,
        int $baseDelay,
        int $maxDelay,
        bool $retryNonIdempotent
    ): callable {
        return Middleware::retry(
            static function (
                int $retries,
                #[\SensitiveParameter] RequestInterface $request,
                ?ResponseInterface $response = null,
                mixed $exception = null
            ) use (
                $maxRetries,
                $retryNonIdempotent
            ): bool {
                // A consumed non-seekable body cannot be replayed: a retry would send it empty.
                if ($retries >= $maxRetries || !$request->getBody()->isSeekable()) {
                    return false;
                }

                $idempotent = $retryNonIdempotent
                    || in_array(strtoupper($request->getMethod()), self::IDEMPOTENT_METHODS, true);

                // A status a handler reports as an exception is judged by the status. Other response-aware
                // failures (a transfer broken after the headers) are judged as network errors below.
                if ($response === null && $exception instanceof BadResponseException) {
                    $response = $exception->getResponse();
                }

                if ($response !== null) {
                    $status = $response->getStatusCode();
                    // 429 means the request was rejected without processing.
                    return $status === 429 || ($idempotent && $status >= 500);
                }

                if (!$exception instanceof \Throwable) {
                    return false;
                }

                // Guzzle's ConnectException means the connection was never established (DNS, TCP connect,
                // TLS setup, connect timeout): nothing was sent, so even POST and PATCH are safe to repeat.
                if ($exception instanceof ConnectException) {
                    return true;
                }

                // Any other failure without a response, or a response that broke off after its headers. A transfer
                // that a callback, the sink or a response size limit stopped would only stop again.
                return $idempotent && (
                    $exception instanceof GuzzleNetworkException || $exception instanceof ResponseTransferException
                );
            },
            static function (int $retries, ?ResponseInterface $response = null) use ($baseDelay, $maxDelay): int {
                $retryAfter = $response !== null ? self::parseRetryAfter($response) : null;
                if ($retryAfter !== null) {
                    return min($retryAfter, $maxDelay);
                }

                $delay = (int) min($maxDelay, $baseDelay * 2 ** min($retries - 1, 30));
                $delay += random_int(0, intdiv($delay, 10));

                return min($delay, $maxDelay);
            }
        );
    }

    /**
     * @return int|null Delay in milliseconds, or null when the header is absent or invalid
     */
    private static function parseRetryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $value) === 1) {
            return (int) $value * 1000;
        }

        // IMF-fixdate, e.g. "Wed, 21 Oct 2015 07:28:00 GMT"
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \\G\\M\\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - time()) * 1000;
    }
}
