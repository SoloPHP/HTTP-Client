<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

/**
 * Validates header names and values without exposing secret values in errors.
 *
 * @internal
 */
final class Headers
{
    /** The guzzlehttp/psr7 rules for header names and values */
    private const string NAME_PATTERN = '/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/D';
    private const string VALUE_PATTERN = '/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D';

    /**
     * Validates a header by the guzzlehttp/psr7 rules up front, so an invalid one fails here instead of at
     * request time, and the message never contains the (possibly secret) value.
     *
     * @return list<string> The values
     */
    public static function values(string $name, #[\SensitiveParameter] mixed $value): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Invalid header name');
        }

        $values = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_string($item) || preg_match(self::VALUE_PATTERN, $item) !== 1) {
                throw new \InvalidArgumentException(
                    "Header {$name} must be a string without control characters"
                );
            }
            $values[] = $item;
        }

        if ($values === []) {
            throw new \InvalidArgumentException("Header {$name} must not be empty");
        }

        return $values;
    }

    /**
     * Checks every header before Guzzle sees it, so its errors cannot expose an invalid secret value.
     *
     * @param array<array-key, mixed> $headers
     */
    public static function validate(#[\SensitiveParameter] array $headers): void
    {
        foreach ($headers as $name => $value) {
            self::values((string) $name, $value);
        }
    }
}
