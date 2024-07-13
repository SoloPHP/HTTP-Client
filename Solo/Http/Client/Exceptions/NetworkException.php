<?php declare(strict_types=1);

namespace Solo\Http\Client\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;

final class NetworkException extends RequestException implements NetworkExceptionInterface
{
}