<?php declare(strict_types=1);

namespace Solo\Http\Client;

use Solo\Http\Client\Exceptions\NetworkException;
use Solo\Http\Client\Exceptions\RequestException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Client implements ClientInterface
{
    private int $timeout;
    private int $sslCertificate;

    public function __construct(int $timeout = 15, int $sslCertificate = 0)
    {
        $this->timeout = $timeout;
        $this->sslCertificate = $sslCertificate;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $curl = curl_init();

        $headers = [];
        foreach ($request->getHeaders() as $key => $values) {
            $headers[] = $key . ': ' . $values[0];
        }

        $options = [
            CURLOPT_URL => (string)$request->getUri(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTP_VERSION => $request->getProtocolVersion(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->sslCertificate === 2,
            CURLOPT_SSL_VERIFYHOST => $this->sslCertificate
        ];

        switch ($request->getMethod()) {
            case 'GET':
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $this->getParsedBody($request);
                break;
            case 'PATCH':
            case 'PUT':
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
                $options[CURLOPT_POSTFIELDS] = $this->getParsedBody($request);
                break;
            default:
                throw new RequestException("Unknown HTTP method: '{$request->getMethod()}'", $request);
        }

        $headerLines = [];
        $options[CURLOPT_HEADERFUNCTION] = function ($curl, $headerLine) use (&$headerLines) {
            $len = strlen($headerLine);
            $headerLines[] = $headerLine;
            return $len;
        };

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if (is_bool($body)) {
            throw new NetworkException(curl_error($curl), $request);
        }
        curl_close($curl);

        $headerLines = $this->discardRedirectsHeaders($headerLines);
        $firstHeader = $this->composeFirstHeader($headerLines[0]);
        $protocolVersion = $firstHeader[1];
        $statusCode = (int)$firstHeader[2];
        $reasonPhrase = $firstHeader[3];
        $responseHeaders = $this->getResponseHeaders($headerLines);

        return new Response($statusCode, $responseHeaders, $body, $protocolVersion, $reasonPhrase);
    }

    private function getParsedBody(RequestInterface $request)
    {
        $body = (string)$request->getBody();
        if (empty($body)) {
            return [];
        }

        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType !== '' && strpos($contentType, 'application/json') !== false) {
            return $body;
        }

        $encode = json_decode($body, true);
        $parsed = [];
        foreach ($encode as $key => $value) {
            if (!is_array($value)) {
                if (strpos($value, '@') === 0) {
                    $parts = explode(';', str_replace('@', '', $value));
                    $parsed[$key] = new \CURLFile($parts[0], $parts[1] ?? '');
                } else {
                    $parsed[$key] = $value;
                }
            } else {
                foreach ($value as $k => $v) {
                    $parsed[$key . '[' . $k . ']'] = $v;
                }
            }
        }
        return $parsed;
    }

    private function discardRedirectsHeaders($headerLines): array
    {
        $lastHttpRequestStartAtIndex = 0;
        for ($i = 0; $i < count($headerLines); ++$i) {
            if (preg_match('/http\/(.+) (\d+) /i', $headerLines[$i])) {
                $lastHttpRequestStartAtIndex = $i;
            }
        }
        return array_slice($headerLines, $lastHttpRequestStartAtIndex);
    }

    private function composeFirstHeader(string $header): array
    {
        preg_match('/http\/(.+) (\d+) (.*)/i', $header, $matches);
        return $matches;
    }

    private function getResponseHeaders(array $headers): array
    {
        array_shift($headers);
        $responseHeaders = [];
        foreach ($headers as $header) {
            $header = explode(':', $header, 2);
            if (count($header) >= 2) {
                $name = strtoupper(trim($header[0]));
                $name = str_replace('-', '_', $name);
                $responseHeaders[$name] = trim($header[1]);
            }
        }
        return $responseHeaders;
    }
}