<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

/**
 * The transfer timed out before a response: while connecting (Guzzle's ConnectTimeoutException) or after sending
 * (its NetworkTimeoutException).
 */
class NetworkTimeoutException extends NetworkException implements TimeoutException
{
}
