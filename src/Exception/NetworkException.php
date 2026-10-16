<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Client\NetworkExceptionInterface;

/**
 * The request could not be completed because of network issues (DNS, connection, timeout).
 */
class NetworkException extends RequestAwareException implements NetworkExceptionInterface
{
}
