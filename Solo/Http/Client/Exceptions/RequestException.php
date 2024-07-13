<?php declare(strict_types=1);

namespace Solo\Http\Client\Exceptions;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

class RequestException extends \RuntimeException implements RequestExceptionInterface
{
    private RequestInterface $request;

    /**
     * Set exception.
     *
     * @param string $message
     * @param RequestInterface $request
     * @param \Exception|null $previous
     */
    public function __construct(string $message, RequestInterface $request, \Exception $previous = null)
    {
        $this->request = $request;
        parent::__construct($message, 0, $previous);
    }

    /**
     * @inheritDoc
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}