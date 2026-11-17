<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed;

use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Throwable;

/**
 * Requests embed documents with Laravel's HTTP client: no redirects, no
 * cookies, a short timeout, and at most 16 MB.
 */
final class HttpFetcher
{
    public const MAX_BYTES = 16_777_216;

    public function __construct(private readonly Factory $http, private readonly float $timeout = 5.0) {}

    /** @throws EmbedException */
    public function __invoke(string $url): Response
    {
        try {
            $answer = $this->http
                ->withOptions(['allow_redirects' => false, 'cookies' => false, 'stream' => true])
                ->timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->accept('application/json')
                ->withUserAgent('framework-embed-statamic/0.1')
                ->get($url);
            $stream = $answer->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof()) {
                $body .= $stream->read(65536);
                if (strlen($body) > self::MAX_BYTES) {
                    throw new EmbedException(EmbedException::INVALID, 'The embed answer is too large.');
                }
            }
        } catch (EmbedException $e) {
            throw $e;
        } catch (HttpClientException|\RuntimeException|\GuzzleHttp\Exception\GuzzleException $e) {
            throw new EmbedException(EmbedException::HTTP, 'The embed request failed: ' . $e->getMessage(), $e);
        }

        return new Response($answer->status(), $answer->headers(), $body);
    }
}
