<?php

declare(strict_types=1);

namespace Framework\Embed\Http;

use Framework\Embed\EmbedException;

/**
 * The default fetcher: curl when the extension is loaded, else PHP streams.
 * It never follows redirects, sends no cookies, and stops reading past
 * $maxBytes. A CMS can pass its own callable instead (see Client).
 */
final class StreamFetcher
{
    public function __construct(private float $timeout = 5.0, private int $maxBytes = 16_777_216, private string $userAgent = 'framework-embed-php/0.1') {}

    public function __invoke(string $url): Response
    {
        return function_exists('curl_init') ? $this->curl($url) : $this->stream($url);
    }

    private function curl(string $url): Response
    {
        $handle = curl_init($url);
        $headers = [];
        $body = '';
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if ($tooLarge) {
            throw new EmbedException(EmbedException::INVALID, 'The embed answer is too large.');
        }
        if ($ok === false) {
            throw new EmbedException(EmbedException::HTTP, 'The embed request failed: ' . $error);
        }

        return new Response($status, $headers, $body);
    }

    private function stream(string $url): Response
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: {$this->userAgent}\r\n",
            'timeout' => $this->timeout,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new EmbedException(EmbedException::HTTP, 'The embed request failed.');
        }
        $body = stream_get_contents($stream, $this->maxBytes + 1);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        if ($body === false) {
            throw new EmbedException(EmbedException::HTTP, 'The embed request failed.');
        }
        if (strlen($body) > $this->maxBytes) {
            throw new EmbedException(EmbedException::INVALID, 'The embed answer is too large.');
        }
        $status = 0;
        $headers = [];
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return new Response($status, $headers, $body);
    }
}
