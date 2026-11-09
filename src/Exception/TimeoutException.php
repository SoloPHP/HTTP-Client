<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The transfer ran out of time: NetworkTimeoutException before a response, ResponseTimeoutException after its
 * headers. A transfer slower than CURLOPT_LOW_SPEED_LIMIT for CURLOPT_LOW_SPEED_TIME is a timeout too.
 */
interface TimeoutException extends ClientExceptionInterface
{
}
