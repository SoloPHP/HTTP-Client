<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

use Psr\Http\Message\StreamInterface;
use Solo\HttpClient\File;

/**
 * Flattens multipart data and prepares validated parts for Guzzle.
 *
 * @internal
 */
final class Multipart
{
    /**
     * Flattens a field map ("tags" => ["a"] becomes "tags[0]") into multipart parts. No I/O happens here.
     *
     * @param array<array-key, mixed> $data
     * @return list<array{name: string, contents: mixed}>
     */
    public static function fields(#[\SensitiveParameter] array $data, string $prefix = ''): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                array_push($fields, ...self::fields($value, $name));
            } elseif (
                $value instanceof File
                || $value instanceof StreamInterface
                || is_resource($value)
                || is_scalar($value)
                || $value === null
            ) {
                $fields[] = ['name' => $name, 'contents' => $value];
            } elseif ($value instanceof \Stringable) {
                $fields[] = ['name' => $name, 'contents' => (string) $value];
            } else {
                throw new \InvalidArgumentException(
                    'Unsupported value for multipart field "' . HeaderParam::encode($name) . '": '
                    . get_debug_type($value)
                );
            }
        }

        return $fields;
    }

    /**
     * Applied to the "multipart" option of every request. Validates all parts before any File stream
     * is opened, expands nested arrays, percent-encodes names and file names, and validates part headers.
     *
     * @param array<array-key, mixed> $parts
     * @return list<array<string, mixed>>
     */
    public static function prepare(#[\SensitiveParameter] array $parts): array
    {
        /** @var list<array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}> */
        $fields = [];

        foreach ($parts as $part) {
            $name = is_array($part) ? ($part['name'] ?? null) : null;
            if (!is_array($part) || !(is_string($name) || is_int($name)) || !array_key_exists('contents', $part)) {
                throw new \InvalidArgumentException('Each multipart part needs a "name" and "contents"');
            }

            if (is_array($part['contents'])) {
                if (isset($part['filename']) || isset($part['headers'])) {
                    throw new \InvalidArgumentException('"filename" and "headers" cannot be used with array contents');
                }
                array_push($fields, ...self::fields($part['contents'], (string) $name));
                continue;
            }

            $field = ['name' => (string) $name, 'contents' => $part['contents']];
            if (isset($part['filename'])) {
                if (!is_string($part['filename'])) {
                    throw new \InvalidArgumentException('Multipart "filename" must be a string');
                }
                $field['filename'] = $part['filename'];
            }
            if (isset($part['headers'])) {
                if (!is_array($part['headers'])) {
                    throw new \InvalidArgumentException('Multipart "headers" must be an array');
                }
                $field['headers'] = [];
                foreach ($part['headers'] as $headerName => $value) {
                    $headerName = (string) $headerName;
                    $field['headers'][$headerName] = implode(', ', Headers::values($headerName, $value));
                }
            }
            $fields[] = $field;
        }

        return array_map(
            /** @param array{name: string, contents: mixed, filename?: string, headers?: array<string, string>} $part */
            static function (array $part): array {
                $file = $part['contents'];
                if ($file instanceof File) {
                    $part['contents'] = $file->openStream();
                    $part['filename'] ??= $file->filename();
                    if ($file->mimeType() !== null) {
                        $part['headers'] = ['Content-Type' => $file->mimeType()] + ($part['headers'] ?? []);
                    }
                }

                $part['name'] = HeaderParam::encode($part['name']);
                if (isset($part['filename'])) {
                    $part['filename'] = HeaderParam::encode($part['filename']);
                }

                return $part;
            },
            $fields
        );
    }
}
