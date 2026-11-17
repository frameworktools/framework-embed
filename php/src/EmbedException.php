<?php

declare(strict_types=1);

namespace Framework\Embed;

use RuntimeException;

/** A URL that isn't allowed, a failed request, or an answer that isn't an embed document. */
final class EmbedException extends RuntimeException
{
    public const NOT_ALLOWED = 'not_allowed';

    public const HTTP = 'http';

    public const INVALID = 'invalid';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
