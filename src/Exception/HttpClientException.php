<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Base exception of the client. Extends RuntimeException for backward compatibility.
 */
class HttpClientException extends \RuntimeException implements ClientExceptionInterface
{
}
