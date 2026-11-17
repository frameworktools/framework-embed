<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Rules;

use Closure;
use Framework\Embed\EmbedException;
use FrameworkCloud\StatamicEmbed\Embedder;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fetches a new artifact URL on save, so a gone, unreachable, or disallowed
 * artifact is a validation error. The fetch is memoized for process().
 */
final class ArtifactRule implements ValidationRule
{
    /** @param array|null $previous The value stored before this save. */
    public function __construct(private readonly ?array $previous) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = trim(is_string($value) ? $value : (is_array($value) ? (string) ($value['url'] ?? '') : ''));
        if ($url === '' || $url === ($this->previous['url'] ?? null)) {
            return;
        }
        try {
            app(Embedder::class)->fetchForStorage($url);
        } catch (EmbedException $e) {
            $fail(Embedder::message($e));
        }
    }
}
