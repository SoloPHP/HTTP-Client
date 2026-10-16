<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

/**
 * Makes a value safe for a quoted multipart header parameter (name="...", filename="...").
 *
 * Like browsers (WHATWG multipart/form-data), quotes and CR/LF are percent-encoded instead of rejected;
 * backslashes and other control characters are encoded too, so the value can never close the quoted
 * string or start a new header line.
 *
 * @internal
 */
final class HeaderParam
{
    public static function encode(string $value): string
    {
        return (string) preg_replace_callback(
            '/["\\\\\x00-\x1F\x7F]/',
            static fn(array $match): string => rawurlencode($match[0]),
            $value
        );
    }
}
