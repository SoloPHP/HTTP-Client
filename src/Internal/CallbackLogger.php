<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use Psr\Log\AbstractLogger;

/**
 * Sends formatted log messages to a callback or PHP's error log.
 *
 * @internal
 */
final class CallbackLogger extends AbstractLogger
{
    /** @var callable|null */
    private $callback;

    public function __construct(?callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->callback === null) {
            $prefix = is_string($level) ? strtoupper($level) : 'INFO';
            error_log("[HttpClient] {$prefix}: {$message}");
            return;
        }

        ($this->callback)((string) $message);
    }
}
