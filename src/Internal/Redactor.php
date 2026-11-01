<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * Removes credentials, query values and registered secrets from text that reaches logs or exception messages.
 *
 * @internal
 */
final class Redactor
{
    private const string MASK = '***';

    /**
     * Absolute URLs inside free text. Whitespace, quotes, backticks and angle brackets end a URL, and it
     * cannot end with punctuation, so "fopen(https://host/x?k=v): failed" keeps its "):".
     */
    private const string URL_PATTERN = '~\b[a-z][a-z0-9+.\-]*://[^\s"\'`<>]*[^\s"\'`<>.,;:!?)]~i';

    /**
     * Builds the strtr() map for secrets: the raw value and the forms it takes inside an encoded URI.
     *
     * @return array<array-key, string>
     */
    public static function redactions(#[\SensitiveParameter] string ...$secrets): array
    {
        $redactions = [];
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $redactions += array_fill_keys([$secret, rawurlencode($secret), urlencode($secret)], self::MASK);
            }
        }

        return $redactions;
    }

    /**
     * @param array<array-key, string> $redactions
     */
    public static function uri(
        #[\SensitiveParameter] UriInterface $uri,
        #[\SensitiveParameter] array $redactions
    ): string {
        $query = $uri->getQuery();
        if ($query !== '') {
            $pairs = array_map(
                static fn(string $pair): string => str_contains($pair, '=')
                    ? strstr($pair, '=', true) . '=' . self::MASK
                    : self::MASK,
                explode('&', $query)
            );
            $query = implode('&', $pairs);
        }

        return self::secrets((string) $uri->withUserInfo('')->withQuery($query)->withFragment(''), $redactions);
    }

    /**
     * Redacts every absolute URL found in the text (not only the request URI: redirect targets and
     * effective URLs appear in Guzzle messages too), then the registered secrets.
     *
     * @param array<array-key, string> $redactions
     */
    public static function message(
        #[\SensitiveParameter] string $text,
        #[\SensitiveParameter] array $redactions
    ): string {
        $text = (string) preg_replace_callback(
            self::URL_PATTERN,
            static function (array $match) use ($redactions): string {
                try {
                    return self::uri(new Uri($match[0]), $redactions);
                } catch (\InvalidArgumentException) {
                    return $match[0];
                }
            },
            $text
        );

        return self::secrets($text, $redactions);
    }

    /**
     * strtr() replaces the longest match first in a single pass, so overlapping secrets are fully masked.
     *
     * @param array<array-key, string> $redactions
     */
    public static function secrets(
        #[\SensitiveParameter] string $text,
        #[\SensitiveParameter] array $redactions
    ): string {
        return $redactions === [] ? $text : strtr($text, $redactions);
    }

    /**
     * Returns the exception unchanged when it holds nothing to hide, so its trace survives. Otherwise
     * returns a copy whose message and whole previous chain are redacted: Guzzle transfer exceptions keep
     * their class, request and response; other exceptions in the chain become RuntimeException naming the
     * original class. A Guzzle subclass with an unexpected constructor is returned unchanged.
     *
     * @param array<array-key, string> $redactions
     */
    public static function exception(\Throwable $e, #[\SensitiveParameter] array $redactions): \Throwable
    {
        $previous = $e->getPrevious() === null ? null : self::exception($e->getPrevious(), $redactions);
        $message = self::message($e->getMessage(), $redactions);

        if ($message === $e->getMessage() && $previous === $e->getPrevious()) {
            return $e;
        }

        if ($e instanceof TransferException) {
            try {
                // The constructors of Guzzle's three transfer branches; ResponseException's is final.
                return match (true) {
                    $e instanceof ResponseException
                        => new ($e::class)($message, $e->getRequest(), $e->getResponse(), $previous),
                    $e instanceof NetworkException => new ($e::class)($message, $e->getRequest(), $previous),
                    default => new ($e::class)($message, $e->getRequest(), (int) $e->getCode(), $previous),
                };
            } catch (\Throwable) {
                return $e;
            }
        }

        return new \RuntimeException($e::class . ': ' . $message, (int) $e->getCode(), $previous);
    }
}
