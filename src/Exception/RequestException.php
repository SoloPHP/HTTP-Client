<?php

declare(strict_types=1);

namespace Solo\HttpClient\Exception;

use Psr\Http\Client\RequestExceptionInterface;

/**
 * The request failed for a reason other than the network.
 */
class RequestException extends RequestAwareException implements RequestExceptionInterface
{
}
