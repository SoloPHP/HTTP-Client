<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

/**
 * The transfer timed out after the response headers, while the body was arriving (Guzzle's
 * ResponseTimeoutException, with the response in getPrevious()).
 */
class ResponseTimeoutException extends RequestException implements TimeoutException
{
}
