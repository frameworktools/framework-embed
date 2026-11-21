<?php

declare(strict_types=1);

namespace Framework\Embed;

/** An artifact URL on an allowed host, as UrlMatcher recognized it. */
final class ArtifactUrl
{
    /**
     * @param 'key'|'path'|'go'|'embed' $type key: /d/{key}; path: /@handle/project/slug; go: /go/{code}, a public artifact's short link; embed: /api/embed/v1/artifacts/{key}
     */
    public function __construct(
        public readonly string $type,
        public readonly string $origin,
        public readonly string $url,
        public readonly ?string $key = null,
        public readonly ?string $handle = null,
        public readonly ?string $project = null,
        public readonly ?string $slug = null,
        public readonly ?int $version = null,
    ) {}

    /** The embed API URL that answers for this artifact URL. */
    public function requestUrl(): string
    {
        return $this->type === 'embed'
            ? $this->url
            : $this->origin . '/api/embed/v1/resolve?url=' . rawurlencode($this->url);
    }
}
