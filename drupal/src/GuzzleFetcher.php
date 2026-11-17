<?php

declare(strict_types=1);

namespace Drupal\framework_embed;

use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Requests embed documents with Drupal's HTTP client: no redirects, no
 * cookies, a five-second timeout, and at most 16 MB.
 */
class GuzzleFetcher {

  public const MAX_BYTES = 16777216;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly float $timeout = 5.0,
  ) {}

  /**
   * Fetches an embed API URL.
   *
   * @throws \Framework\Embed\EmbedException
   */
  public function __invoke(string $url): Response {
    try {
      $answer = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'cookies' => FALSE,
        'http_errors' => FALSE,
        'timeout' => $this->timeout,
        'connect_timeout' => $this->timeout,
        'stream' => TRUE,
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'framework-embed-drupal/0.1',
        ],
      ]);
      $stream = $answer->getBody();
      $body = '';
      while (!$stream->eof()) {
        $body .= $stream->read(65536);
        if (strlen($body) > self::MAX_BYTES) {
          throw new EmbedException(EmbedException::INVALID, 'The embed answer is too large.');
        }
      }
    }
    catch (GuzzleException | \RuntimeException $e) {
      if ($e instanceof EmbedException) {
        throw $e;
      }
      throw new EmbedException(EmbedException::HTTP, 'The embed request failed: ' . $e->getMessage(), $e);
    }

    return new Response($answer->getStatusCode(), $answer->getHeaders(), $body);
  }

}
