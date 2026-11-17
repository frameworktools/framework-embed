<?php

declare(strict_types=1);

namespace Framework\Embed\Http;

final class Response
{
    /** @var array<string, string> Header names in lower case. */
    public readonly array $headers;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(public readonly int $status, array $headers = [], public readonly string $body = '')
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
